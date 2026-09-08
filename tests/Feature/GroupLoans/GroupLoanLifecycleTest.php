<?php

use App\Actions\GroupLoans\ActivateGroupLoanAction;
use App\Actions\GroupLoans\ApplyGroupLoanDepositAction;
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
});

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

function activateMemberLoan(GroupLoan $loan, User $by): GroupLoan
{
    app(RecordGroupLoanDepositAction::class)->execute($loan, $loan->security_deposit_amount, $by);

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

it('records the security deposit as a held liability', function (): void {
    $loan = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);

    app(RecordGroupLoanDepositAction::class)->execute($loan, 100_00, $this->agent);

    $loan->refresh();
    $depositAccount = app(ChartOfAccounts::class)->groupLoanDepositLiability($loan);

    expect($loan->deposit_status)->toBe(DepositStatus::Held)
        ->and($depositAccount->refresh()->balance)->toBe(100_00)
        ->and($loan->deposits()->where('type', 'held')->count())->toBe(1);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('will not activate before the deposit is held', function (): void {
    $loan = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);

    expect(fn () => app(ActivateGroupLoanAction::class)->execute($loan, $this->agent))
        ->toThrow(ValidationException::class);
});

it('activates: generates the schedule and disburses the principal', function (): void {
    $loan = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);
    $active = activateMemberLoan($loan, $this->agent);

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
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent);

    $result = app(RecordGroupLoanRepaymentAction::class)->execute($loan, 250_00, $this->agent);

    expect($result->groupLoan->outstanding_balance)->toBe(750_00);

    $installments = $loan->installments()->orderBy('sequence')->get();
    expect($installments[0]->status)->toBe(InstallmentStatus::Paid)
        ->and($installments[1]->status)->toBe(InstallmentStatus::Paid)
        ->and($installments[2]->status)->toBe(InstallmentStatus::PartiallyPaid)
        ->and($installments[2]->amount_paid)->toBe(50_00);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('auto-closes and refunds the still-held deposit when fully repaid in cash', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent);

    $result = app(RecordGroupLoanRepaymentAction::class)->execute($loan, 1000_00, $this->agent);

    $loan->refresh();
    $depositAccount = app(ChartOfAccounts::class)->groupLoanDepositLiability($loan);

    expect($result->groupLoan->status)->toBe(GroupLoanStatus::Closed)
        ->and($loan->deposit_status)->toBe(DepositStatus::Settled)
        ->and($loan->deposits()->where('type', 'refunded')->count())->toBe(1)
        ->and($depositAccount->refresh()->balance)->toBe(0);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('rejects a repayment larger than the outstanding balance', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent);

    expect(fn () => app(RecordGroupLoanRepaymentAction::class)->execute($loan, 1000_01, $this->agent))
        ->toThrow(ValidationException::class);
});

it('is idempotent on client_reference for deposits and repayments', function (): void {
    $loan = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);

    $ref = (string) Str::uuid();
    app(RecordGroupLoanDepositAction::class)->execute($loan, 100_00, $this->agent, clientReference: $ref);
    $second = app(RecordGroupLoanDepositAction::class)->execute($loan->fresh(), 100_00, $this->agent, clientReference: $ref);

    expect($second->duplicate)->toBeTrue()
        ->and($loan->fresh()->deposits()->count())->toBe(1);

    $active = app(ActivateGroupLoanAction::class)->execute($loan->fresh(), $this->agent);

    $repayRef = (string) Str::uuid();
    app(RecordGroupLoanRepaymentAction::class)->execute($active, 200_00, $this->agent, clientReference: $repayRef);
    $dup = app(RecordGroupLoanRepaymentAction::class)->execute($active->fresh(), 200_00, $this->agent, clientReference: $repayRef);

    expect($dup->duplicate)->toBeTrue()
        ->and($active->fresh()->outstanding_balance)->toBe(800_00);
});

it('applies a held deposit against the balance on demand with no cash movement', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent);

    $branchCash = app(ChartOfAccounts::class)->branchCash($this->branch);

    app(RecordGroupLoanRepaymentAction::class)->execute($loan, 200_00, $this->agent);
    $loan->refresh();

    $cashBefore = $branchCash->refresh()->balance;
    $offset = app(ApplyGroupLoanDepositAction::class)->execute($loan, $this->agent);

    expect($offset->outstanding_balance)->toBe(700_00)
        ->and($offset->deposit_status)->toBe(DepositStatus::Settled)
        ->and($offset->deposits()->where('type', 'applied')->count())->toBe(1)
        ->and($branchCash->refresh()->balance)->toBe($cashBefore); // no cash moved

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('refunds the excess in cash and closes the loan when the deposit exceeds the balance', function (): void {
    $loan = activateMemberLoan(
        issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer, principal: 1000_00, deposit: 300_00, periodic: 100_00),
        $this->agent,
    );

    app(RecordGroupLoanRepaymentAction::class)->execute($loan, 900_00, $this->agent);
    $loan->refresh();

    $branchCash = app(ChartOfAccounts::class)->branchCash($this->branch);
    $cashBefore = $branchCash->refresh()->balance;

    $result = app(ApplyGroupLoanDepositAction::class)->execute($loan, $this->agent);

    expect($result->status)->toBe(GroupLoanStatus::Closed)
        ->and($result->outstanding_balance)->toBe(0)
        ->and($result->deposits()->where('type', 'applied')->sum('amount'))->toBe(100_00)
        ->and($result->deposits()->where('type', 'refunded')->sum('amount'))->toBe(200_00)
        // the 200_00 excess is refunded to the member in cash
        ->and($branchCash->refresh()->balance)->toBe($cashBefore - 200_00);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('refuses to apply the deposit twice', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent);
    app(RecordGroupLoanRepaymentAction::class)->execute($loan, 200_00, $this->agent);

    app(ApplyGroupLoanDepositAction::class)->execute($loan->fresh(), $this->agent);

    expect(fn () => app(ApplyGroupLoanDepositAction::class)->execute($loan->fresh(), $this->agent))
        ->toThrow(ValidationException::class);
});

it('writes off an active loan: seizes the deposit then bad-debts the residual', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent);
    app(RecordGroupLoanRepaymentAction::class)->execute($loan, 400_00, $this->agent);
    $loan->refresh();

    $writtenOff = app(WriteOffGroupLoanAction::class)->execute($loan, $this->manager, 'Absconded');

    $receivable = app(ChartOfAccounts::class)->groupLoanReceivable($writtenOff);
    $badDebt = app(ChartOfAccounts::class)->badDebtExpense($this->branch->company);

    expect($writtenOff->status)->toBe(GroupLoanStatus::WrittenOff)
        ->and($writtenOff->outstanding_balance)->toBe(0)
        ->and($writtenOff->write_off_amount)->toBe(600_00)
        ->and($writtenOff->deposit_status)->toBe(DepositStatus::Settled)
        ->and($writtenOff->deposits()->where('type', 'seized')->sum('amount'))->toBe(100_00)
        ->and($receivable->refresh()->balance)->toBe(0)
        ->and($badDebt->refresh()->balance)->toBe(500_00);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('refuses to write off a non-active or fully repaid loan', function (): void {
    $draft = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);
    expect(fn () => app(WriteOffGroupLoanAction::class)->execute($draft, $this->manager, 'x'))
        ->toThrow(ValidationException::class);

    $loan = activateMemberLoan(
        issueMemberLoan($this->agent, $this->loanGroup->fresh(), Customer::factory()->forBranch($this->branch)->create()),
        $this->agent,
    );
    app(RecordGroupLoanRepaymentAction::class)->execute($loan, 1000_00, $this->agent);

    expect(fn () => app(WriteOffGroupLoanAction::class)->execute($loan->fresh(), $this->manager, 'x'))
        ->toThrow(ValidationException::class);
});

it('allows only one active loan per member at a time', function (): void {
    $loan = activateMemberLoan(issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer), $this->agent);

    expect(fn () => issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer))
        ->toThrow(ValidationException::class);

    // repay & close, then a fresh loan is allowed
    app(RecordGroupLoanRepaymentAction::class)->execute($loan, 1000_00, $this->agent);

    $second = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer);
    expect($second->status)->toBe(GroupLoanStatus::Draft);
});

it('uses the client_reference as the group loan id', function (): void {
    $ref = (string) Str::uuid();
    $loan = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer, clientReference: $ref);

    expect($loan->id)->toBe($ref);

    $again = issueMemberLoan($this->agent, $this->loanGroup->fresh(), $this->customer, clientReference: $ref);
    expect($again->id)->toBe($ref);
});
