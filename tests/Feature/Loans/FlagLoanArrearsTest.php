<?php

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Reports\BuildPortfolioAtRiskAction;
use App\Enums\InstallmentStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
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

it('flags past-due group-loan installments overdue without a penalty', function (): void {
    $late = GroupLoan::factory()->active()->create([
        'branch_id' => $this->branch->id,
        'start_date' => now()->subDays(2)->toDateString(),
    ]);
    $dueToday = GroupLoan::factory()->active()->create([
        'branch_id' => $this->branch->id,
        'start_date' => now()->toDateString(),
    ]);

    $this->artisan('loans:flag-arrears')->assertSuccessful();

    $lateInstallments = $late->installments()->orderBy('sequence')->get();
    expect($lateInstallments[0]->status)->toBe(InstallmentStatus::Overdue)
        ->and($lateInstallments[1]->status)->toBe(InstallmentStatus::Pending)
        ->and($dueToday->installments()->orderBy('sequence')->first()->status)->toBe(InstallmentStatus::Pending)
        ->and($late->refresh()->outstanding_balance)->toBe($late->principal_amount);
});

it('counts group loans in portfolio-at-risk and the defaulters report', function (): void {
    $groupLoan = GroupLoan::factory()->active()->create([
        'branch_id' => $this->branch->id,
        'start_date' => now()->subWeek()->toDateString(),
    ]);
    $this->artisan('loans:flag-arrears')->assertSuccessful();

    $par = app(BuildPortfolioAtRiskAction::class)->execute($this->branch);
    expect($par['at_risk'])->toBe($groupLoan->principal_amount)
        ->and($par['outstanding'])->toBe($groupLoan->principal_amount);

    $this->actingAs($this->manager, 'sanctum')
        ->getJson('/api/v1/reports/defaulters?branch_id='.$this->branch->id)
        ->assertOk()
        ->assertJsonCount(1, 'data.group_installments')
        ->assertJsonPath('data.group_installments.0.loan_number', $groupLoan->loan_number);
});
