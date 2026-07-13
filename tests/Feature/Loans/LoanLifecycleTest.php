<?php

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Loans\RecordLoanRepaymentAction;
use App\Actions\Loans\RejectLoanAction;
use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->customer = Customer::factory()->forBranch($this->branch)->create();

    $this->product = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'interest_method' => 'flat',
        'interest_rate_bps' => 300, // 3% per period
        'term_period_count' => 3,
        'repayment_frequency' => 'monthly',
        'origination_fee_amount' => 0,
        'min_amount' => 100_00,
        'max_amount' => 1_000_00,
    ]);
});

function applyLoan(User $agent, Customer $customer, LoanProduct $product, int $amount = 300_00): Loan
{
    return app(ApplyForLoanAction::class)->execute(
        submittedBy: $agent,
        customer: $customer,
        product: $product,
        requestedAmount: $amount,
    );
}

it('walks a loan through apply -> reject', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    expect($loan->status)->toBe(LoanStatus::Applied);

    $rejected = app(RejectLoanAction::class)->execute($loan, $this->manager, 'Insufficient history');

    expect($rejected->status)->toBe(LoanStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('Insufficient history');
});

it('refuses to approve a loan that is not in applied status', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(RejectLoanAction::class)->execute($loan, $this->manager, 'no');

    expect(fn () => app(ApproveLoanAction::class)->execute($loan->fresh(), $this->manager))
        ->toThrow(ValidationException::class);
});

it('disburses an approved loan: debits receivable, credits branch cash, builds the schedule', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);

    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    // Flat interest: intdiv(30000 * 300, 10000) = 900 per period, 3 periods = 2700.
    expect($disbursed->status)->toBe(LoanStatus::Disbursed)
        ->and($disbursed->total_interest)->toBe(2_700)
        ->and($disbursed->outstanding_balance)->toBe($disbursed->total_repayable)
        ->and($disbursed->installments)->toHaveCount(3);

    $receivable = $disbursed->receivableAccount;
    expect($receivable->refresh()->balance)->toBe($disbursed->principal_amount);

    $branchCash = app(ChartOfAccounts::class)->branchCash($this->branch);
    expect($branchCash->refresh()->balance)->toBe(-$disbursed->principal_amount); // cash left the branch
});

it('books the origination fee separately from what the customer repays', function (): void {
    $this->product->update(['origination_fee_amount' => 10_00]);
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);

    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    $branchCash = app(ChartOfAccounts::class)->branchCash($this->branch);
    $feeIncome = app(ChartOfAccounts::class)->loanFeeIncome($this->branch->company);

    // Customer owes the full principal + interest even though they only received principal - fee.
    expect($branchCash->refresh()->balance)->toBe(-(300_00 - 10_00))
        ->and($feeIncome->refresh()->balance)->toBe(10_00)
        ->and($disbursed->total_repayable)->toBe(300_00 + $disbursed->total_interest);
});

it('applies a repayment across installments, oldest first, interest before principal', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    $firstInstallment = $disbursed->installments->first();
    $repaymentAmount = $firstInstallment->totalDue();

    $result = app(RecordLoanRepaymentAction::class)->execute($disbursed, $repaymentAmount, $this->manager);

    expect($result->duplicate)->toBeFalse()
        ->and($firstInstallment->refresh()->status->value)->toBe('paid')
        ->and($result->loan->outstanding_balance)->toBe($disbursed->outstanding_balance - $repaymentAmount);
});

it('closes the loan once fully repaid', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    $result = app(RecordLoanRepaymentAction::class)->execute($disbursed, $disbursed->outstanding_balance, $this->manager);

    expect($result->loan->status)->toBe(LoanStatus::Closed)
        ->and($result->loan->outstanding_balance)->toBe(0)
        ->and($result->loan->closed_at)->not->toBeNull();

    foreach ($result->loan->installments as $installment) {
        expect($installment->status->value)->toBe('paid');
    }
});

it('is idempotent when the same client_reference is replayed', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    $ref = (string) Str::uuid();
    $action = app(RecordLoanRepaymentAction::class);

    $first = $action->execute($disbursed, 100_00, $this->manager, clientReference: $ref);
    $second = $action->execute($disbursed->fresh(), 100_00, $this->manager, clientReference: $ref);

    expect($second->duplicate)->toBeTrue()
        ->and($second->entry->id)->toBe($first->entry->id)
        ->and($disbursed->fresh()->outstanding_balance)->toBe($disbursed->outstanding_balance - 100_00); // only applied once
});

it('rejects a repayment larger than the outstanding balance', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    expect(fn () => app(RecordLoanRepaymentAction::class)->execute($disbursed, $disbursed->outstanding_balance + 1, $this->manager))
        ->toThrow(ValidationException::class);
});

it('queues exactly one customer notification per disbursement and per repayment', function (): void {
    Notification::fake();

    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    expect(NotificationLog::where('customer_id', $this->customer->id)->count())->toBe(1);

    app(RecordLoanRepaymentAction::class)->execute($disbursed, 50_00, $this->manager);

    expect(NotificationLog::where('customer_id', $this->customer->id)->count())->toBe(2);
});

it('applies a repayment to penalty before interest and principal, keeping the ledger balanced', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    $firstInstallment = $disbursed->installments->first();
    $firstInstallment->update(['due_date' => now()->subDays(10)]); // past the 3-day grace period
    $this->artisan('loans:flag-arrears');
    $firstInstallment->refresh();

    expect($firstInstallment->penalty_due)->toBeGreaterThan(0);

    $repaymentAmount = $firstInstallment->totalDue();
    app(RecordLoanRepaymentAction::class)->execute($disbursed->fresh(), $repaymentAmount, $this->manager);

    $penaltyIncome = app(ChartOfAccounts::class)->loanPenaltyIncome($this->branch->company);
    expect($firstInstallment->fresh()->remainingPenalty())->toBe(0)
        ->and($penaltyIncome->refresh()->balance)->toBe($firstInstallment->penalty_due);

    // Debits must still equal credits even with a third (penalty) income line.
    $this->artisan('ledger:verify-balances')->assertSuccessful();
});
