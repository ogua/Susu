<?php

use App\Actions\Billing\RecordInvoicePaymentAction;
use App\Actions\Billing\RunBillingCycleAction;
use App\Actions\Billing\SubscribeCompanyAction;
use App\Actions\Company\CreateBranchAction;
use App\Actions\Company\SetCompanyActiveStatusAction;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Pages\Billing;
use App\Filament\SuperAdmin\Resources\Companies\Pages\ViewCompany;
use App\Filament\SuperAdmin\Resources\Plans\Pages\CreatePlan;
use App\Filament\SuperAdmin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();
    Notification::fake();
    config([
        'billing.grace_days' => 7,
        'billing.suspend_after_days' => 7,
        'services.paystack.secret_key' => 'test_secret_key',
        'services.paystack.base_url' => 'https://api.paystack.co',
    ]);

    $this->company = Company::factory()->create();
    $this->branch = Branch::factory()->for($this->company)->create();
});

it('starts a trial and invoices the first period when the trial ends', function (): void {
    $plan = Plan::factory()->withTrial(14)->create();

    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, $plan);

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->invoices()->count())->toBe(0);

    $this->travel(15)->days();
    $summary = app(RunBillingCycleAction::class)->execute();

    $subscription->refresh();
    expect($summary['trials_converted'])->toBe(1)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->invoices()->count())->toBe(1)
        ->and($subscription->invoices()->first()->amount)->toBe($plan->price_amount);
});

it('invoices the first period straight away for a plan without a trial', function (): void {
    $plan = Plan::factory()->create(['price_amount' => 350_00]);

    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, $plan);
    $invoice = $subscription->invoices()->sole();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->amount)->toBe(350_00)
        ->and($invoice->status)->toBe(InvoiceStatus::Unpaid)
        ->and($invoice->due_at->toDateString())->toBe(now()->addDays(7)->toDateString());
});

it('settles a free plan\'s invoices automatically', function (): void {
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->free()->create());

    expect($subscription->invoices()->sole()->status)->toBe(InvoiceStatus::Paid);
});

it('marks an overdue subscription past due, then suspends the company, and payment restores both', function (): void {
    $plan = Plan::factory()->create();
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, $plan);
    $invoice = $subscription->invoices()->sole();

    $this->travel(8)->days();
    app(RunBillingCycleAction::class)->execute();
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($this->company->refresh()->is_active)->toBeTrue();

    $this->travel(8)->days();
    $summary = app(RunBillingCycleAction::class)->execute();
    expect($summary['suspended'])->toBe(1)
        ->and($this->company->refresh()->is_active)->toBeFalse()
        ->and($this->company->suspended_reason)->toBe(Company::SUSPENDED_FOR_NON_PAYMENT);

    app(RecordInvoicePaymentAction::class)->execute($invoice, 'bank_transfer', 'BNK-1');

    expect($this->company->refresh()->is_active)->toBeTrue()
        ->and($this->company->suspended_reason)->toBeNull()
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
});

it('does not lift an operator\'s own suspension when an invoice is paid', function (): void {
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->create());
    app(SetCompanyActiveStatusAction::class)->execute($this->company, false);

    app(RecordInvoicePaymentAction::class)->execute($subscription->invoices()->sole(), 'cash');

    expect($this->company->refresh()->is_active)->toBeFalse()
        ->and($this->company->suspended_reason)->toBe(Company::SUSPENDED_BY_OPERATOR);
});

it('renews every elapsed period, even after missed runs, without duplicating invoices', function (): void {
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->free()->create());

    $this->travel(3)->months();
    app(RunBillingCycleAction::class)->execute();
    app(RunBillingCycleAction::class)->execute();

    expect($subscription->invoices()->count())->toBe(4)
        ->and($subscription->refresh()->current_period_end->isFuture())->toBeTrue();
});

it('changes plan without re-billing the current period', function (): void {
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->create());
    $bigger = Plan::factory()->create(['price_amount' => 900_00]);

    app(SubscribeCompanyAction::class)->execute($this->company, $bigger);

    expect($subscription->refresh()->plan_id)->toBe($bigger->id)
        ->and($subscription->invoices()->count())->toBe(1);
});

it('refuses to void a paid invoice', function (): void {
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->free()->create());

    app(RecordInvoicePaymentAction::class)->void($subscription->invoices()->sole());
})->throws(ValidationException::class);

it('enforces plan limits on branches, staff and customers', function (): void {
    $plan = Plan::factory()->limited(['branches' => 1, 'staff' => 1, 'customers' => 0])->create();
    app(SubscribeCompanyAction::class)->execute($this->company, $plan);
    $admin = User::factory()->companyAdmin($this->company)->create();

    expect(fn () => app(CreateBranchAction::class)->execute($this->company, ['name' => 'Second', 'slug' => 'second']))
        ->toThrow(ValidationException::class, 'Your plan allows 1 branches');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/agent/customers', [
            'branch_id' => $this->branch->id,
            'first_name' => 'Ama',
            'last_name' => 'Mensah',
            'phone' => '+233241112223',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('customers');
});

it('has no limits for a company without a subscription', function (): void {
    app(CreateBranchAction::class)->execute($this->company, ['name' => 'Second', 'slug' => 'second']);

    expect($this->company->branches()->count())->toBe(2);
});

it('settles an invoice from the Paystack webhook', function (): void {
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->create(['price_amount' => 100_00]));
    $invoice = $subscription->invoices()->sole();
    $invoice->update(['provider_reference' => 'SUSULIC-SUB-abc']);

    $body = json_encode([
        'event' => 'charge.success',
        'data' => ['reference' => 'SUSULIC-SUB-abc', 'status' => 'success', 'amount' => 100_00],
    ]);

    $this->call('POST', '/api/webhooks/paystack/license', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'test_secret_key'),
    ], $body)->assertOk();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->payment_method)->toBe('paystack');
});

it('ignores a webhook that paid less than the invoice', function (): void {
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->create(['price_amount' => 100_00]));
    $invoice = $subscription->invoices()->sole();
    $invoice->update(['provider_reference' => 'SUSULIC-SUB-short']);

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'SUSULIC-SUB-short', 'status' => 'success', 'amount' => 1_00]]);

    $this->call('POST', '/api/webhooks/paystack/license', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'test_secret_key'),
    ], $body)->assertOk();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Unpaid);
});

it('lets a company admin pay an invoice through Paystack from the Billing page', function (): void {
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->create(['price_amount' => 100_00]));
    $invoice = $subscription->invoices()->sole();
    $admin = User::factory()->companyAdmin($this->company)->create();

    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/inv']]),
        'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 100_00]]),
    ]);

    $this->actingAs($admin);
    bootAdminPanelWithTenant($this->branch);

    livewire(Billing::class)
        ->assertOk()
        ->assertSee($invoice->number)
        ->callAction(TestAction::make('pay')->table($invoice))
        ->assertRedirect('https://checkout.paystack.com/inv');

    $reference = $invoice->refresh()->provider_reference;
    expect($reference)->toStartWith('SUSULIC-SUB-');

    $this->get('/billing/callback?reference='.$reference)->assertRedirect();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
});

it('shows the billing page only to company admins', function (): void {
    $manager = User::factory()->branchManager($this->branch)->create();
    $this->actingAs($manager);
    bootAdminPanelWithTenant($this->branch);

    expect(Billing::canAccess())->toBeFalse();
});

it('warns a company admin about an overdue invoice', function (): void {
    app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->create());
    $admin = User::factory()->companyAdmin($this->company)->create();
    $this->travel(8)->days();

    $this->actingAs($admin);
    bootAdminPanelWithTenant($this->branch);

    expect(Billing::overdueNotice())->toContain('is overdue');
});

it('returns the subscription to company admins over the API', function (): void {
    app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->limited(['branches' => 3])->create(['name' => 'Growth']));
    $admin = User::factory()->companyAdmin($this->company)->create();

    $this->actingAs($admin, 'sanctum')->getJson('/api/v1/company/subscription')
        ->assertOk()
        ->assertJsonPath('data.plan.name', 'Growth')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.usage.branches.limit', 3)
        ->assertJsonPath('data.usage.branches.used', 1)
        ->assertJsonCount(1, 'data.open_invoices');
});

it('lets a super admin create a plan, subscribe a company and record a payment', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $this->actingAs($superAdmin);
    bootSuperAdminPanel();

    livewire(CreatePlan::class)
        ->fillForm([
            'name' => 'Starter',
            'code' => 'STARTER',
            'price_amount' => '150.00',
            'billing_period' => 'monthly',
            'trial_days' => 0,
            'max_branches' => 2,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = Plan::where('code', 'STARTER')->sole();
    expect($plan->price_amount)->toBe(150_00);

    livewire(ViewCompany::class, ['record' => $this->company->getKey()])
        ->callAction('changePlan', ['plan_id' => $plan->id])
        ->assertNotified();

    $invoice = SubscriptionInvoice::where('company_id', $this->company->id)->sole();

    livewire(ListSubscriptionInvoices::class)
        ->callAction(TestAction::make('recordPayment')->table($invoice), ['method' => 'mobile_money', 'reference' => 'MOMO-9'])
        ->assertNotified();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->recorded_by)->toBe($superAdmin->id);
});

it('lets a company admin pay an invoice from the apps through the API', function (): void {
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->create(['price_amount' => 100_00]));
    $invoice = $subscription->invoices()->sole();
    $admin = User::factory()->companyAdmin($this->company)->create();

    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/app']]),
        'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 100_00]]),
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/company/subscription/invoices/{$invoice->id}/checkout")
        ->assertOk()
        ->assertJsonPath('authorization_url', 'https://checkout.paystack.com/app');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'initialize')
        && $request['callback_url'] === route('billing.return'));

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/company/subscription/invoices/{$invoice->id}/verify")
        ->assertOk()
        ->assertJsonCount(0, 'data.open_invoices');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
});

it('refuses checkout of another company\'s invoice', function (): void {
    $other = Company::factory()->create();
    Branch::factory()->for($other)->create();
    $invoice = app(SubscribeCompanyAction::class)->execute($other, Plan::factory()->create())->invoices()->sole();
    $admin = User::factory()->companyAdmin($this->company)->create();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/company/subscription/invoices/{$invoice->id}/checkout")
        ->assertNotFound();
});

it('shows a no-session return page after paying in the app browser', function (): void {
    $invoice = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->create(['price_amount' => 100_00]))->invoices()->sole();
    $invoice->update(['provider_reference' => 'SUSULIC-SUB-ret']);

    Http::fake([
        'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 100_00]]),
    ]);

    $this->get('/billing/return?reference=SUSULIC-SUB-ret')->assertOk()->assertSee('Payment received');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
});
