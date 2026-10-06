<?php

use App\Actions\Billing\SubscribeCompanyAction;
use App\Enums\BillingPeriod;
use App\Filament\Pages\Billing;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();
    Notification::fake();
    $this->company = Company::factory()->create();
    $this->branch = Branch::factory()->for($this->company)->create();
    $this->admin = User::factory()->companyAdmin($this->company)->create();
    $this->small = Plan::factory()->create(['name' => 'Small', 'price_amount' => 100_00, 'max_branches' => 1]);
    $this->big = Plan::factory()->create(['name' => 'Big', 'price_amount' => 300_00, 'max_branches' => 5]);
});

it('bills an upgrade pro rata for the rest of the period', function (): void {
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, $this->small);
    $periodEnd = $subscription->current_period_end;
    $this->travelTo($subscription->current_period_start->copy()->addSeconds(
        (int) ($subscription->current_period_start->diffInSeconds($periodEnd) / 2),
    ));

    app(SubscribeCompanyAction::class)->execute($this->company, $this->big);

    $proration = $subscription->invoices()->latest('period_start')->first();
    expect($subscription->refresh()->plan_id)->toBe($this->big->id)
        ->and($subscription->invoices()->count())->toBe(2)
        ->and($proration->amount)->toBeGreaterThanOrEqual(99_00)->toBeLessThanOrEqual(101_00)
        ->and($proration->period_end->equalTo($periodEnd))->toBeTrue();
});

it('does not bill a downgrade until the next period', function (): void {
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, $this->big);

    app(SubscribeCompanyAction::class)->execute($this->company, $this->small);

    expect($subscription->refresh()->plan_id)->toBe($this->small->id)
        ->and($subscription->invoices()->count())->toBe(1);
});

it('refuses a downgrade the company has outgrown', function (): void {
    app(SubscribeCompanyAction::class)->execute($this->company, $this->big);
    Branch::factory()->for($this->company)->create();

    app(SubscribeCompanyAction::class)->execute($this->company, $this->small);
})->throws(ValidationException::class, 'The Small plan is too small');

it('starts a new full period when switching to a yearly plan', function (): void {
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, $this->small);
    $yearly = Plan::factory()->create(['price_amount' => 1_000_00, 'billing_period' => BillingPeriod::Yearly]);
    $this->travel(1)->minutes();

    app(SubscribeCompanyAction::class)->execute($this->company, $yearly);

    $latest = $subscription->invoices()->latest('period_start')->first();
    expect($latest->amount)->toBe(1_000_00)
        ->and($subscription->refresh()->current_period_end->isAfter(now()->addMonths(11)))->toBeTrue();
});

it('lets a company admin change plan on the Billing page', function (): void {
    app(SubscribeCompanyAction::class)->execute($this->company, $this->small);
    $this->actingAs($this->admin);
    bootAdminPanelWithTenant($this->branch);

    livewire(Billing::class)
        ->callAction('changePlan', ['plan_id' => $this->big->id])
        ->assertNotified();

    expect($this->company->subscription->refresh()->plan_id)->toBe($this->big->id);
});

it('lists plans and changes plan over the API', function (): void {
    Plan::factory()->create(['name' => 'Retired', 'is_active' => false]);

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/company/subscription/plans')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Small');

    $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/company/subscription/plan', ['plan_id' => $this->big->id])
        ->assertOk()
        ->assertJsonPath('data.plan.name', 'Big')
        ->assertJsonPath('data.open_invoices.0.amount', 300_00);

    $agent = User::factory()->fieldAgent($this->branch)->create();
    $this->actingAs($agent, 'sanctum')->postJson('/api/v1/company/subscription/plan', ['plan_id' => $this->small->id])->assertForbidden();
});
