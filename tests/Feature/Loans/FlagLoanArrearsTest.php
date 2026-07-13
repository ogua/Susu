<?php

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Enums\InstallmentStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->customer = Customer::factory()->forBranch($this->branch)->create();

    $this->product = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'interest_method' => 'flat',
        'interest_rate_bps' => 300,
        'term_period_count' => 3,
        'repayment_frequency' => 'monthly',
        'penalty_rate_bps' => 1_000, // 10%
        'grace_period_days' => 3,
        'min_amount' => 100_00,
        'max_amount' => 1_000_00,
    ]);
});

function disburseLoan(User $agent, Customer $customer, LoanProduct $product, User $manager): Loan
{
    $loan = app(ApplyForLoanAction::class)->execute($agent, $customer, $product, 300_00);
    app(ApproveLoanAction::class)->execute($loan, $manager);

    return app(DisburseLoanAction::class)->execute($loan->fresh(), $manager);
}

it('flags an installment overdue past its grace period and accrues a penalty', function (): void {
    $loan = disburseLoan($this->agent, $this->customer, $this->product, $this->manager);
    $firstInstallment = $loan->installments->first();
    $firstInstallment->update(['due_date' => now()->subDays(10)]); // well past the 3-day grace period

    $this->artisan('loans:flag-arrears')->assertSuccessful();

    $firstInstallment->refresh();
    $expectedPenalty = intdiv(($firstInstallment->principal_due + $firstInstallment->interest_due) * 1_000, 10_000);

    expect($firstInstallment->status)->toBe(InstallmentStatus::Overdue)
        ->and($firstInstallment->penalty_due)->toBe($expectedPenalty)
        ->and($loan->fresh()->outstanding_balance)->toBe($loan->outstanding_balance + $expectedPenalty);
});

it('does not flag an installment still within its grace period', function (): void {
    $loan = disburseLoan($this->agent, $this->customer, $this->product, $this->manager);
    $firstInstallment = $loan->installments->first();
    $firstInstallment->update(['due_date' => now()->subDay()]); // 1 day late, grace is 3 days

    $this->artisan('loans:flag-arrears');

    expect($firstInstallment->fresh()->status)->toBe(InstallmentStatus::Pending)
        ->and($loan->fresh()->outstanding_balance)->toBe($loan->outstanding_balance);
});

it('never re-charges a penalty on a second run', function (): void {
    $loan = disburseLoan($this->agent, $this->customer, $this->product, $this->manager);
    $firstInstallment = $loan->installments->first();
    $firstInstallment->update(['due_date' => now()->subDays(10)]);

    $this->artisan('loans:flag-arrears');
    $penaltyAfterFirstRun = $firstInstallment->fresh()->penalty_due;
    $balanceAfterFirstRun = $loan->fresh()->outstanding_balance;

    $this->artisan('loans:flag-arrears');

    expect($firstInstallment->fresh()->penalty_due)->toBe($penaltyAfterFirstRun)
        ->and($loan->fresh()->outstanding_balance)->toBe($balanceAfterFirstRun);
});
