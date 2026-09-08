<?php

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Savings\RequestWithdrawalAction;
use App\Enums\LoanStatus;
use App\Enums\WithdrawalStatus;
use App\Filament\Widgets\PendingLoanApplicationsWidget;
use App\Filament\Widgets\PendingWithdrawalRequestsWidget;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Filament\Actions\Testing\TestAction;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
});

// bootAdminPanelWithTenant() is declared globally in CustomerResourceTest.php
// and reused here — Pest loads every test file's top-level declarations
// regardless of --filter, so redeclaring it would fatal.

it('shows a pending loan application and lets a manager approve it from the widget', function (): void {
    $product = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'min_amount' => 100_00,
        'max_amount' => 1_000_00,
    ]);
    $customer = Customer::factory()->forBranch($this->branch)->create();
    $loan = app(ApplyForLoanAction::class)->execute($this->agent, $customer, $product, 300_00);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire(PendingLoanApplicationsWidget::class)
        ->assertOk()
        ->assertSee($loan->loan_number)
        ->callAction(TestAction::make('approve')->table($loan))
        ->assertNotified();

    expect($loan->refresh()->status)->toBe(LoanStatus::Approved);
});

it('shows a pending withdrawal request and lets a manager approve it from the widget', function (): void {
    $product = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
    ]);
    $customer = Customer::factory()->forBranch($this->branch)->create();
    $account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $customer->id,
        'savings_product_id' => $product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
        'balance' => 1000,
    ]);
    $request = app(RequestWithdrawalAction::class)->execute($this->manager, $account, 500);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire(PendingWithdrawalRequestsWidget::class)
        ->assertOk()
        ->assertSee($account->account_number)
        ->callAction(TestAction::make('approve')->table($request))
        ->assertNotified();

    expect($request->fresh()->status)->toBe(WithdrawalStatus::Approved);
});

it('denies field agents from viewing the checker inbox widgets', function (): void {
    $this->actingAs($this->agent);
    bootAdminPanelWithTenant($this->branch);

    expect(PendingLoanApplicationsWidget::canView())->toBeFalse()
        ->and(PendingWithdrawalRequestsWidget::canView())->toBeFalse();
});

it('does not show a loan already approved and disbursed', function (): void {
    $product = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'min_amount' => 100_00,
        'max_amount' => 1_000_00,
    ]);
    $customer = Customer::factory()->forBranch($this->branch)->create();
    $loan = app(ApplyForLoanAction::class)->execute($this->agent, $customer, $product, 300_00);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire(PendingLoanApplicationsWidget::class)
        ->assertOk()
        ->assertDontSee($loan->loan_number);
});
