<?php

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Loans\RecordLoanRepaymentAction;
use App\Actions\Loans\RejectLoanAction;
use App\Actions\Loans\RestructureLoanAction;
use App\Actions\Loans\TopUpLoanAction;
use App\Actions\Loans\WriteOffLoanAction;
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

it('uses the client_reference as the loan id when provided', function (): void {
    // Offline clients (desktop/mobile) generate this id themselves; later ops
    // in the same lifecycle (approve/disburse/repayment) reference loan_id
    // directly, so it must match once synced — mirrors CreateCustomerAction.
    $ref = (string) Str::uuid();

    $loan = app(ApplyForLoanAction::class)->execute(
        submittedBy: $this->agent,
        customer: $this->customer,
        product: $this->product,
        requestedAmount: 300_00,
        clientReference: $ref,
    );

    expect($loan->id)->toBe($ref);
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

it('writes off a disbursed loan, zeroing the receivable and recognizing a bad debt expense', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);
    $outstandingBeforeWriteOff = $disbursed->outstanding_balance;
    // The receivable only ever holds principal (interest is recognized as
    // income solely when collected) — since nothing was repaid, this equals
    // principal_amount, less than the full outstanding_balance (which
    // includes unrealized interest).
    $principalOutstanding = $disbursed->receivableAccount->balance;

    $writtenOff = app(WriteOffLoanAction::class)->execute($disbursed->fresh(), $this->manager, 'Borrower absconded');

    expect($writtenOff->status)->toBe(LoanStatus::WrittenOff)
        ->and($writtenOff->outstanding_balance)->toBe(0)
        ->and($writtenOff->write_off_amount)->toBe($outstandingBeforeWriteOff) // full business loss, incl. interest
        ->and($writtenOff->write_off_reason)->toBe('Borrower absconded')
        ->and($writtenOff->written_off_at)->not->toBeNull();

    $receivable = $writtenOff->receivableAccount;
    expect($receivable->refresh()->balance)->toBe(0);

    $badDebtExpense = app(ChartOfAccounts::class)->badDebtExpense($this->branch->company);
    expect($badDebtExpense->refresh()->balance)->toBe($principalOutstanding);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('refuses to write off a loan that is not disbursed', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);

    expect(fn () => app(WriteOffLoanAction::class)->execute($loan, $this->manager, 'no'))
        ->toThrow(ValidationException::class);
});

it('refuses to write off a loan with no outstanding balance', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);
    app(RecordLoanRepaymentAction::class)->execute($disbursed, $disbursed->outstanding_balance, $this->manager);

    expect(fn () => app(WriteOffLoanAction::class)->execute($disbursed->fresh(), $this->manager, 'no'))
        ->toThrow(ValidationException::class);
});

it('restructures a disbursed loan onto a new product, closing the old loan and opening a fresh linked one', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);
    $principalOutstanding = $disbursed->receivableAccount->balance;

    $newProduct = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'interest_method' => 'flat',
        'interest_rate_bps' => 500,
        'term_period_count' => 6,
        'repayment_frequency' => 'monthly',
        'origination_fee_amount' => 0,
        'min_amount' => 1,
        'max_amount' => 1_000_000_00,
    ]);

    $newLoan = app(RestructureLoanAction::class)->execute($disbursed->fresh(), $this->manager, $newProduct, 'Struggling to keep up with the old schedule');

    expect($newLoan->status)->toBe(LoanStatus::Disbursed)
        ->and($newLoan->previous_loan_id)->toBe($disbursed->id)
        ->and($newLoan->rolled_over_amount)->toBe($principalOutstanding)
        ->and($newLoan->principal_amount)->toBe($principalOutstanding)
        ->and($newLoan->loan_product_id)->toBe($newProduct->id)
        ->and($newLoan->interest_rate_bps)->toBe(500)
        ->and($newLoan->term_period_count)->toBe(6)
        ->and($newLoan->installments)->toHaveCount(6)
        ->and($newLoan->outstanding_balance)->toBe($newLoan->total_repayable);

    $oldLoan = $disbursed->fresh();
    expect($oldLoan->status)->toBe(LoanStatus::Refinanced)
        ->and($oldLoan->outstanding_balance)->toBe(0)
        ->and($oldLoan->refinance_type)->toBe('restructure')
        ->and($oldLoan->refinance_reason)->toBe('Struggling to keep up with the old schedule')
        ->and($oldLoan->refinance_amount)->toBe($disbursed->outstanding_balance)
        ->and($oldLoan->refinanced_at)->not->toBeNull()
        ->and($oldLoan->receivableAccount->refresh()->balance)->toBe(0);

    $newReceivable = $newLoan->receivableAccount;
    expect($newReceivable->refresh()->balance)->toBe($newLoan->principal_amount);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('refuses to restructure a loan that is not disbursed', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);

    expect(fn () => app(RestructureLoanAction::class)->execute($loan, $this->manager, $this->product, 'no'))
        ->toThrow(ValidationException::class);
});

it('refuses to restructure a loan with no outstanding principal', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);
    app(RecordLoanRepaymentAction::class)->execute($disbursed, $disbursed->outstanding_balance, $this->manager);

    expect(fn () => app(RestructureLoanAction::class)->execute($disbursed->fresh(), $this->manager, $this->product, 'no'))
        ->toThrow(ValidationException::class);
});

it('tops up a disbursed loan with fresh cash, rolling the old principal into the new one', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);
    $principalOutstanding = $disbursed->receivableAccount->balance;

    $branchCashBefore = app(ChartOfAccounts::class)->branchCash($this->branch)->refresh()->balance;

    $newLoan = app(TopUpLoanAction::class)->execute($disbursed->fresh(), $this->manager, 200_00, 'Customer requested more capital');

    expect($newLoan->status)->toBe(LoanStatus::Disbursed)
        ->and($newLoan->previous_loan_id)->toBe($disbursed->id)
        ->and($newLoan->rolled_over_amount)->toBe($principalOutstanding)
        ->and($newLoan->principal_amount)->toBe($principalOutstanding + 200_00)
        ->and($newLoan->loan_product_id)->toBe($disbursed->loan_product_id)
        ->and($newLoan->interest_rate_bps)->toBe($disbursed->interest_rate_bps);

    $oldLoan = $disbursed->fresh();
    expect($oldLoan->status)->toBe(LoanStatus::Refinanced)
        ->and($oldLoan->outstanding_balance)->toBe(0)
        ->and($oldLoan->refinance_type)->toBe('top_up')
        ->and($oldLoan->receivableAccount->refresh()->balance)->toBe(0);

    $newReceivable = $newLoan->receivableAccount;
    expect($newReceivable->refresh()->balance)->toBe($newLoan->principal_amount);

    $branchCash = app(ChartOfAccounts::class)->branchCash($this->branch);
    expect($branchCash->refresh()->balance)->toBe($branchCashBefore - 200_00);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('refuses to top up a loan that is not disbursed', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);

    expect(fn () => app(TopUpLoanAction::class)->execute($loan, $this->manager, 100_00, 'no'))
        ->toThrow(ValidationException::class);
});

it('refuses a top-up amount that is not positive', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    expect(fn () => app(TopUpLoanAction::class)->execute($disbursed->fresh(), $this->manager, 0, 'no'))
        ->toThrow(ValidationException::class);
});

it('refuses to top up a loan with an overdue installment', function (): void {
    $loan = applyLoan($this->agent, $this->customer, $this->product);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    $firstInstallment = $disbursed->installments->first();
    $firstInstallment->update(['due_date' => now()->subDays(10)]);
    $this->artisan('loans:flag-arrears');

    expect(fn () => app(TopUpLoanAction::class)->execute($disbursed->fresh(), $this->manager, 100_00, 'no'))
        ->toThrow(ValidationException::class);
});
