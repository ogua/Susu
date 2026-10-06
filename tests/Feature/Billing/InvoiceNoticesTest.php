<?php

use App\Actions\Billing\RecordInvoicePaymentAction;
use App\Actions\Billing\RunBillingCycleAction;
use App\Actions\Billing\SubscribeCompanyAction;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\SubscriptionInvoiceNotice;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    seedRoles();
    Notification::fake();
    config(['billing.grace_days' => 7, 'billing.reminder_days_before' => 3, 'billing.suspend_after_days' => 7]);

    $this->company = Company::factory()->create();
    Branch::factory()->for($this->company)->create();
    $this->admin = User::factory()->companyAdmin($this->company)->create();
});

it('emails company admins when an invoice is issued, as a reminder, when overdue, and a receipt when paid', function (): void {
    $invoice = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->create())->invoices()->sole();

    Notification::assertSentTo($this->admin, SubscriptionInvoiceNotice::class, fn (SubscriptionInvoiceNotice $n, array $channels): bool => $n->type === SubscriptionInvoiceNotice::ISSUED && $channels === ['mail', 'database']);

    $this->travel(5)->days();
    app(RunBillingCycleAction::class)->execute();
    app(RunBillingCycleAction::class)->execute();
    Notification::assertSentToTimes($this->admin, SubscriptionInvoiceNotice::class, 2);
    expect($invoice->refresh()->reminder_sent_at)->not->toBeNull();

    $this->travel(3)->days();
    $summary = app(RunBillingCycleAction::class)->execute();
    expect($summary['overdue_notices'])->toBe(1);
    Notification::assertSentTo($this->admin, SubscriptionInvoiceNotice::class, fn (SubscriptionInvoiceNotice $n): bool => $n->type === SubscriptionInvoiceNotice::OVERDUE);

    app(RecordInvoicePaymentAction::class)->execute($invoice, 'bank_transfer');
    Notification::assertSentTo($this->admin, SubscriptionInvoiceNotice::class, fn (SubscriptionInvoiceNotice $n): bool => $n->type === SubscriptionInvoiceNotice::PAID);
});

it('attaches the invoice PDF to the email', function (): void {
    $invoice = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->create())->invoices()->sole();

    $mail = (new SubscriptionInvoiceNotice($invoice, SubscriptionInvoiceNotice::ISSUED))->toMail($this->admin);

    expect($mail->rawAttachments)->toHaveCount(1)
        ->and($mail->rawAttachments[0]['name'])->toBe("invoice-{$invoice->number}.pdf")
        ->and(substr($mail->rawAttachments[0]['data'], 0, 4))->toBe('%PDF');
});

it('falls back to the company contact email when it has no active admin', function (): void {
    $this->admin->update(['is_active' => false]);

    app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->create());

    Notification::assertSentTo(new AnonymousNotifiable, SubscriptionInvoiceNotice::class, fn ($n, $channels, $notifiable): bool => $notifiable->routes['mail'] === $this->company->contact_email);
});

it('serves the invoice PDF to the company admin, super admins and signed app links only', function (): void {
    $invoice = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->create())->invoices()->sole();
    $stranger = User::factory()->companyAdmin(Company::factory()->create())->create();

    $this->actingAs($this->admin)->get(route('billing.invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs(User::factory()->superAdmin()->create())->get(route('billing.invoices.pdf', $invoice))->assertOk();
    $this->actingAs($stranger)->get(route('billing.invoices.pdf', $invoice))->assertForbidden();

    $url = $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/v1/company/subscription/invoices/{$invoice->id}/pdf-url")
        ->assertOk()
        ->json('url');

    auth()->forgetGuards();
    $this->get($url)->assertOk();
    $this->get(route('billing.invoices.pdf', $invoice))->assertForbidden();
});
