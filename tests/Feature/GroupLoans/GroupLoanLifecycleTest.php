<?php

use App\Actions\GroupLoans\ActivateGroupLoanAction;
use App\Actions\GroupLoans\CancelGroupLoanAction;
use App\Actions\GroupLoans\IssueGroupMemberLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanDepositAction;
use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Actions\GroupLoans\WriteOffGroupLoanAction;
use App\Enums\DepositStatus;
use App\Enums\GroupLoanStatus;
use App\Enums\InstallmentStatus;
use App\Enums\LoanFrequency;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();

    $this->loanGroup = LoanGroup::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
    ]);

    $this->customer = Customer::factory()->forBranch($this->branch)->create();
    $this->savingsAccount = openSavingsAccount($this->branch, $this->customer);
});

function openSavingsAccount(Branch $branch, Customer $customer): SavingsAccount
{
    return SavingsAccount::factory()->create([
        'branch_id' => $branch->id,
        'company_id' => $branch->company_id,
        'customer_id' => $customer->id,
    ]);
}

function issueMemberLoan(
    User $agent,
    LoanGroup $group,
    Customer $customer,
    int $principal = 1000_00,
    int $deposit = 100_00,
    int $periodic = 100_00,
    ?string $clientReference = null,
): GroupLoan {
    return app(IssueGroupMemberLoanAction::class)->execute(
        issuedBy: $agent,
        loanGroup: $group,
        customer: $customer,
        principal: $principal,
        securityDeposit: $deposit,
        periodicAmount: $periodic,
        frequency: LoanFrequency::Weekly,
        startDate: Carbon::now(),
        clientReference: $clientReference,
    );
}

function activateMemberLoan(GroupLoan $loan, User $by, SavingsAccount $savingsAccount): GroupLoan
{
    app(RecordGroupLoanDepositAction::class)->execute($loan, $savingsAccount, $loan->security_deposit_amount, $by);

    return app(ActivateGroupLoanAction::class)->execute($loan->fresh(), $by);
}

it('issues a draft loan and roster member without touching the ledger', function (): void {
    $loan = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);

    expect($loan->status)->toBe(GroupLoanStatus::Draft)
        ->and($loan->deposit_status)->toBe(DepositStatus::Pending)
        ->and($loan->outstanding_balance)->toBe(0)
        ->and($loan->total_periods)->toBe(10)
        ->and($this->loanGroup->members()->where('customer_id', $this->customer->id)->exists())->toBeTrue()
        ->and($loan->installments()->count())->toBe(0);
});

it('records the security deposit into the chosen savings account', function (): void {
    $loan = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);

    app(RecordGroupLoanDepositAction::class)->execute($loan, $this->savingsAccount, 100_00, $this->agent);

    $loan->refresh();
    $this->savingsAccount->refresh();

    expect($loan->deposit_status)->toBe(DepositStatus::Held)
        ->and($this->savingsAccount->balance)->toBe(100_00)
        ->and($this->savingsAccount->ledgerAccount->refresh()->balance)->toBe(100_00)
        ->and($loan->deposits()->where('type', 'held')->count())->toBe(1)
        ->and($loan->deposits()->first()->savings_account_id)->toBe($this->savingsAccount->id);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('rejects a deposit into a savings account that does not belong to the borrower', function (): void {
    $loan = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);
    $otherAccount = openSavingsAccount($this->branch, Customer::factory()->forBranch($this->branch)->create());

    expect(fn () => app(RecordGroupLoanDepositAction::class)->execute($loan, $otherAccount, 100_00, $this->agent))
        ->toThrow(ValidationException::class);
});

it('will not activate before the deposit is held', function (): void {
    $loan = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);

    expect(fn () => app(ActivateGroupLoanAction::class)->execute($loan, $this->agent))
        ->toThrow(ValidationException::class);
});

it('activates: generates the schedule and disburses the principal', function (): void {
    $loan = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);
    $active = activateMemberLoan($loan, $this->agent, $this->savingsAccount);

    $receivable = app(ChartOfAccounts::class)->groupLoanReceivable($active);
    $branchCash = app(ChartOfAccounts::class)->branchCash($this->branch);

    expect($active->status)->toBe(GroupLoanStatus::Active)
        ->and($active->outstanding_balance)->toBe(1000_00)
        ->and($active->installments()->count())->toBe(10)
        ->and($active->installments()->sum('amount_due'))->toBe(1000_00)
        ->and($receivable->refresh()->balance)->toBe(1000_00)
        // deposit (+100_00) minus disbursed principal (-1000_00)
        ->and($branchCash->refresh()->balance)->toBe(100_00 - 1000_00);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('applies a repayment oldest-first and reduces the outstanding balance', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent, $this->savingsAccount);

    $result = app(RecordGroupLoanRepaymentAction::class)->execute($loan, 250_00, $this->agent);

    expect($result->groupLoan->outstanding_balance)->toBe(750_00);

    $installments = $loan->installments()->orderBy('sequence')->get();
    expect($installments[0]->status)->toBe(InstallmentStatus::Paid)
        ->and($installments[1]->status)->toBe(InstallmentStatus::Paid)
        ->and($installments[2]->status)->toBe(InstallmentStatus::PartiallyPaid)
        ->and($installments[2]->amount_paid)->toBe(50_00);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('auto-closes without touching savings when fully repaid in cash', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent, $this->savingsAccount);

    $balanceBefore = $this->savingsAccount->fresh()->balance;
    $result = app(RecordGroupLoanRepaymentAction::class)->execute($loan, 1000_00, $this->agent);

    $loan->refresh();

    expect($result->groupLoan->status)->toBe(GroupLoanStatus::Closed)
        ->and($loan->deposit_status)->toBe(DepositStatus::Held)
        ->and($loan->deposits()->where('type', 'refunded')->count())->toBe(0)
        ->and($this->savingsAccount->fresh()->balance)->toBe($balanceBefore);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('rejects a repayment larger than the outstanding balance', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent, $this->savingsAccount);

    expect(fn () => app(RecordGroupLoanRepaymentAction::class)->execute($loan, 1000_01, $this->agent))
        ->toThrow(ValidationException::class);
});

it('is idempotent on client_reference for deposits and repayments', function (): void {
    $loan = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);

    $ref = (string) Str::uuid();
    app(RecordGroupLoanDepositAction::class)->execute($loan, $this->savingsAccount, 100_00, $this->agent, clientReference: $ref);
    $second = app(RecordGroupLoanDepositAction::class)->execute($loan->fresh(), $this->savingsAccount, 100_00, $this->agent, clientReference: $ref);

    expect($second->duplicate)->toBeTrue()
        ->and($loan->fresh()->deposits()->count())->toBe(1);

    $active = app(ActivateGroupLoanAction::class)->execute($loan->fresh(), $this->agent);

    $repayRef = (string) Str::uuid();
    app(RecordGroupLoanRepaymentAction::class)->execute($active, 200_00, $this->agent, clientReference: $repayRef);
    $dup = app(RecordGroupLoanRepaymentAction::class)->execute($active->fresh(), 200_00, $this->agent, clientReference: $repayRef);

    expect($dup->duplicate)->toBeTrue()
        ->and($active->fresh()->outstanding_balance)->toBe(800_00);
});

it('writes off an active loan: applies chosen savings then bad-debts only the residual', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent, $this->savingsAccount);
    app(RecordGroupLoanRepaymentAction::class)->execute($loan, 400_00, $this->agent);
    $loan->refresh();

    // The account already holds the 100_00 deposit paid in earlier — enough
    // to cover the 100_00 applied below with no top-up needed.
    $depositsBefore = $loan->deposits()->count();

    $writtenOff = app(WriteOffGroupLoanAction::class)->execute(
        $loan,
        $this->manager,
        'Absconded',
        savingsAccount: $this->savingsAccount,
        savingsAmountApplied: 100_00,
    );

    $receivable = app(ChartOfAccounts::class)->groupLoanReceivable($writtenOff);
    $badDebt = app(ChartOfAccounts::class)->badDebtExpense($this->branch->company);

    expect($writtenOff->status)->toBe(GroupLoanStatus::WrittenOff)
        ->and($writtenOff->outstanding_balance)->toBe(0)
        ->and($writtenOff->write_off_amount)->toBe(600_00)
        ->and($writtenOff->write_off_savings_account_id)->toBe($this->savingsAccount->id)
        ->and($writtenOff->write_off_savings_applied)->toBe(100_00)
        ->and($writtenOff->deposit_status)->toBe(DepositStatus::Held)
        ->and($writtenOff->deposits()->count())->toBe($depositsBefore) // no new deposit-lifecycle row
        ->and($receivable->refresh()->balance)->toBe(0)
        ->and($badDebt->refresh()->balance)->toBe(500_00);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('write-off works with no savings applied at all', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent, $this->savingsAccount);
    app(RecordGroupLoanRepaymentAction::class)->execute($loan, 400_00, $this->agent);
    $loan->refresh();

    $writtenOff = app(WriteOffGroupLoanAction::class)->execute($loan, $this->manager, 'Absconded');

    $badDebt = app(ChartOfAccounts::class)->badDebtExpense($this->branch->company);

    expect($writtenOff->write_off_amount)->toBe(600_00)
        ->and($writtenOff->write_off_savings_account_id)->toBeNull()
        ->and($writtenOff->write_off_savings_applied)->toBe(0)
        ->and($badDebt->refresh()->balance)->toBe(600_00);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('write-off caps savings applied at the account balance', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent, $this->savingsAccount);
    app(RecordGroupLoanRepaymentAction::class)->execute($loan, 400_00, $this->agent);
    $loan->refresh();

    expect(fn () => app(WriteOffGroupLoanAction::class)->execute(
        $loan,
        $this->manager,
        'Absconded',
        savingsAccount: $this->savingsAccount,
        savingsAmountApplied: $this->savingsAccount->fresh()->balance + 1,
    ))->toThrow(ValidationException::class);
});

it('write-off caps savings applied at the outstanding balance', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent, $this->savingsAccount);
    app(RecordGroupLoanRepaymentAction::class)->execute($loan, 400_00, $this->agent);
    $loan->refresh();

    $this->savingsAccount->forceFill(['balance' => 10_000_00])->save();

    expect(fn () => app(WriteOffGroupLoanAction::class)->execute(
        $loan,
        $this->manager,
        'Absconded',
        savingsAccount: $this->savingsAccount,
        savingsAmountApplied: $loan->outstanding_balance + 1,
    ))->toThrow(ValidationException::class);
});

it('refuses to write off a non-active or fully repaid loan', function (): void {
    $draft = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);
    expect(fn () => app(WriteOffGroupLoanAction::class)->execute($draft, $this->manager, 'x'))
        ->toThrow(ValidationException::class);

    $otherCustomer = Customer::factory()->forBranch($this->branch)->create();
    $loan = activateMemberLoan(
        issueMemberLoan($this->agent, $this->loanGroup->fresh(), $otherCustomer),
        $this->agent,
        openSavingsAccount($this->branch, $otherCustomer),
    );
    app(RecordGroupLoanRepaymentAction::class)->execute($loan, 1000_00, $this->agent);

    expect(fn () => app(WriteOffGroupLoanAction::class)->execute($loan->fresh(), $this->manager, 'x'))
        ->toThrow(ValidationException::class);
});

it('allows only one active loan per member at a time', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent, $this->savingsAccount);

    expect(fn () => issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer))
        ->toThrow(ValidationException::class);

    // repay & close, then a fresh loan is allowed
    app(RecordGroupLoanRepaymentAction::class)->execute($loan, 1000_00, $this->agent);

    $second = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);
    expect($second->status)->toBe(GroupLoanStatus::Draft);
});

it('refuses a second loan while the first is still a draft awaiting deposit or activation', function (): void {
    $draft = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);

    expect(fn () => issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer))
        ->toThrow(ValidationException::class, "This member already has loan {$draft->loan_number} awaiting its security deposit and activation.");

    app(RecordGroupLoanDepositAction::class)->execute($draft, $this->savingsAccount, $draft->security_deposit_amount, $this->agent);

    expect(fn () => issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer))
        ->toThrow(ValidationException::class)
        ->and(GroupLoan::where('customer_id', $this->customer->id)->count())->toBe(1);
});

it('cancels a draft loan, leaves any paid deposit in savings, and frees the member for a new loan', function (): void {
    $draft = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);
    app(RecordGroupLoanDepositAction::class)->execute($draft, $this->savingsAccount, $draft->security_deposit_amount, $this->agent);

    $cancelled = app(CancelGroupLoanAction::class)->execute($draft->fresh(), $this->agent, 'Issued to the wrong member');

    expect($cancelled->status)->toBe(GroupLoanStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->cancelled_by)->toBe($this->agent->id)
        ->and($cancelled->cancellation_reason)->toBe('Issued to the wrong member')
        ->and($this->savingsAccount->fresh()->balance)->toBe(100_00)
        ->and($cancelled->loanGroupMember->openLoan)->toBeNull();

    expect(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer)->status)->toBe(GroupLoanStatus::Draft);
});

it('treats re-cancelling a cancelled loan as a no-op', function (): void {
    $draft = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);
    $first = app(CancelGroupLoanAction::class)->execute($draft, $this->agent);

    $again = app(CancelGroupLoanAction::class)->execute($first, $this->manager, 'replay');

    expect($again->cancelled_by)->toBe($this->agent->id)
        ->and($again->cancellation_reason)->toBeNull();
});

it('refuses to cancel an active loan', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent, $this->savingsAccount);

    expect(fn () => app(CancelGroupLoanAction::class)->execute($loan, $this->agent))
        ->toThrow(ValidationException::class);
});

it('uses the client_reference as the group loan id', function (): void {
    $ref = (string) Str::uuid();
    $loan = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer, clientReference: $ref);

    expect($loan->id)->toBe($ref);

    $again = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer, clientReference: $ref);
    expect($again->id)->toBe($ref);
});
