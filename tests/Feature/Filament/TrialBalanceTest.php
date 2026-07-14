<?php

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Loans\RecordLoanRepaymentAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Filament\Pages\TrialBalance;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
});

it('stays balanced after a mix of collections, loan disbursement, and repayment', function (): void {
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
    ]);
    app(RecordCollectionAction::class)->execute($this->agent, $account, 500);

    $loanProduct = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'min_amount' => 100_00,
        'max_amount' => 1_000_00,
    ]);
    $loan = app(ApplyForLoanAction::class)->execute($this->agent, $customer, $loanProduct, 300_00);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);
    app(RecordLoanRepaymentAction::class)->execute($disbursed, 50_00, $this->manager);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    $page = livewire(TrialBalance::class)->assertOk();

    expect($page->instance()->totalDebits())
        ->toBe($page->instance()->totalCredits())
        ->toBeGreaterThan(0);
    expect($page->instance()->isBalanced())->toBeTrue();
});

it('denies field agents access to the trial balance', function (): void {
    $this->actingAs($this->agent);

    expect(TrialBalance::canAccess())->toBeFalse();
});

it('lets a branch manager access the trial balance', function (): void {
    $this->actingAs($this->manager);

    expect(TrialBalance::canAccess())->toBeTrue();
});
