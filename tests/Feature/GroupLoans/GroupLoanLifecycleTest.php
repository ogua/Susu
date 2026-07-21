<?php

use App\Actions\GroupLoans\ApplyForGroupLoanAction;
use App\Actions\GroupLoans\ApproveGroupLoanAction;
use App\Actions\GroupLoans\DisburseGroupLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Actions\GroupLoans\RejectGroupLoanAction;
use App\Actions\GroupLoans\WriteOffGroupLoanAction;
use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Enums\GroupLoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
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

    $this->product = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'interest_method' => 'flat',
        'interest_rate_bps' => 300, // 3% per period
        'term_period_count' => 3,
        'repayment_frequency' => 'monthly',
        'origination_fee_amount' => 0,
        'min_amount' => 100_00,
        'max_amount' => 10_000_00,
    ]);

    // 3 members so a non-divisible principal exercises the rounding remainder.
    $this->members = collect(range(1, 3))->map(function () {
        $customer = Customer::factory()->forBranch($this->branch)->create();

        return app(AddLoanGroupMemberAction::class)->execute($this->loanGroup, $customer);
    });
});

function applyGroupLoan(User $agent, LoanGroup $loanGroup, LoanProduct $product, int $amount = 1000_00): GroupLoan
{
    return app(ApplyForGroupLoanAction::class)->execute(
        submittedBy: $agent,
        loanGroup: $loanGroup,
        product: $product,
        requestedAmount: $amount,
    );
}

it('walks a group loan through apply -> reject', function (): void {
    $groupLoan = applyGroupLoan($this->agent, $this->loanGroup->fresh(), $this->product);
    expect($groupLoan->status)->toBe(GroupLoanStatus::Applied);

    $rejected = app(RejectGroupLoanAction::class)->execute($groupLoan, $this->manager, 'Not enough active members');

    expect($rejected->status)->toBe(GroupLoanStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('Not enough active members');
});

it('disburses an approved group loan and splits the principal evenly, remainder to the last member', function (): void {
    $groupLoan = applyGroupLoan($this->agent, $this->loanGroup->fresh(), $this->product, 1000_00);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);

    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);

    expect($disbursed->status)->toBe(GroupLoanStatus::Disbursed)
        ->and($disbursed->member_count_at_disbursement)->toBe(3)
        ->and($disbursed->installments)->toHaveCount(3);

    // intdiv(100000, 3) = 33333, remainder 1 goes to the last member (joined_at order).
    $borrowers = $disbursed->borrowers()->orderBy('created_at')->get();
    expect($borrowers)->toHaveCount(3)
        ->and($borrowers[0]->share_principal)->toBe(33_333)
        ->and($borrowers[1]->share_principal)->toBe(33_333)
        ->and($borrowers[2]->share_principal)->toBe(33_334)
        ->and($borrowers->sum('share_principal'))->toBe($disbursed->principal_amount);

    $receivable = $disbursed->receivableAccount;
    expect($receivable->refresh()->balance)->toBe($disbursed->principal_amount);

    $branchCash = app(ChartOfAccounts::class)->branchCash($this->branch);
    expect($branchCash->refresh()->balance)->toBe(-$disbursed->principal_amount);
});

it('refuses to disburse a group loan whose membership dropped below 2 since application', function (): void {
    $groupLoan = applyGroupLoan($this->agent, $this->loanGroup->fresh(), $this->product);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);

    // Drop membership to 1 active member after approval, before disbursement.
    $this->members->skip(1)->each(fn ($member) => $member->update(['status' => 'left']));

    expect(fn () => app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager))
        ->toThrow(ValidationException::class);
});

it('applies one members repayment against the shared schedule and only that borrowers share_outstanding moves', function (): void {
    $groupLoan = applyGroupLoan($this->agent, $this->loanGroup->fresh(), $this->product);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);

    $payingBorrower = $disbursed->borrowers()->orderBy('created_at')->first();
    $otherBorrower = $disbursed->borrowers()->orderBy('created_at')->skip(1)->first();

    // Small, partial amount — well within the paying member's own principal
    // share (33_333) and the first installment's total due, so this exercises
    // the ordinary (non-overpaying) path distinctly from the dedicated
    // overpayment test below.
    $repaymentAmount = 10_000;

    $result = app(RecordGroupLoanRepaymentAction::class)->execute($payingBorrower, $repaymentAmount, $this->manager);

    expect($result->duplicate)->toBeFalse()
        ->and($result->groupLoan->outstanding_balance)->toBe($disbursed->outstanding_balance - $repaymentAmount)
        ->and($payingBorrower->fresh()->share_outstanding)->toBe($payingBorrower->share_principal - $repaymentAmount)
        ->and($otherBorrower->fresh()->share_outstanding)->toBe($otherBorrower->share_principal); // untouched
});

it('floors an overpaying members share_outstanding at 0 without reallocating the excess onto a co-member', function (): void {
    $groupLoan = applyGroupLoan($this->agent, $this->loanGroup->fresh(), $this->product);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);

    $payingBorrower = $disbursed->borrowers()->orderBy('created_at')->first();
    $otherBorrower = $disbursed->borrowers()->orderBy('created_at')->skip(1)->first();

    // Pay more than this one member's own share — a valid joint-liability flow.
    $overpayment = $payingBorrower->share_principal + 100_00;
    $result = app(RecordGroupLoanRepaymentAction::class)->execute($payingBorrower, $overpayment, $this->manager);

    expect($payingBorrower->fresh()->share_outstanding)->toBe(0) // floored, not negative
        ->and($otherBorrower->fresh()->share_outstanding)->toBe($otherBorrower->share_principal) // never reallocated
        ->and($result->groupLoan->outstanding_balance)->toBe($disbursed->outstanding_balance - $overpayment); // full amount still applied
});

it('closes the group loan once the shared outstanding balance is fully repaid', function (): void {
    $groupLoan = applyGroupLoan($this->agent, $this->loanGroup->fresh(), $this->product);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);

    $borrower = $disbursed->borrowers()->orderBy('created_at')->first();
    $result = app(RecordGroupLoanRepaymentAction::class)->execute($borrower, $disbursed->outstanding_balance, $this->manager);

    expect($result->groupLoan->status)->toBe(GroupLoanStatus::Closed)
        ->and($result->groupLoan->outstanding_balance)->toBe(0)
        ->and($result->groupLoan->closed_at)->not->toBeNull();

    foreach ($result->groupLoan->installments as $installment) {
        expect($installment->status->value)->toBe('paid');
    }
});

it('is idempotent when the same client_reference is replayed', function (): void {
    $groupLoan = applyGroupLoan($this->agent, $this->loanGroup->fresh(), $this->product);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);
    $borrower = $disbursed->borrowers()->orderBy('created_at')->first();

    $ref = (string) Str::uuid();
    $action = app(RecordGroupLoanRepaymentAction::class);

    $first = $action->execute($borrower, 100_00, $this->manager, clientReference: $ref);
    $second = $action->execute($borrower->fresh(), 100_00, $this->manager, clientReference: $ref);

    expect($second->duplicate)->toBeTrue()
        ->and($second->entry->id)->toBe($first->entry->id)
        ->and($disbursed->fresh()->outstanding_balance)->toBe($disbursed->outstanding_balance - 100_00); // only applied once
});

it('rejects a repayment larger than the shared outstanding balance', function (): void {
    $groupLoan = applyGroupLoan($this->agent, $this->loanGroup->fresh(), $this->product);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);
    $borrower = $disbursed->borrowers()->orderBy('created_at')->first();

    expect(fn () => app(RecordGroupLoanRepaymentAction::class)->execute($borrower, $disbursed->outstanding_balance + 1, $this->manager))
        ->toThrow(ValidationException::class);
});

it('writes off a disbursed group loan, zeroing the shared receivable, without touching any borrower share', function (): void {
    $groupLoan = applyGroupLoan($this->agent, $this->loanGroup->fresh(), $this->product);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);
    $outstandingBeforeWriteOff = $disbursed->outstanding_balance;
    // The receivable only ever holds principal (interest is recognized as
    // income solely when collected) — since nothing was repaid, this equals
    // principal_amount, less than the full outstanding_balance.
    $principalOutstanding = $disbursed->receivableAccount->balance;
    $borrowerSharesBefore = $disbursed->borrowers()->pluck('share_outstanding', 'id');

    $writtenOff = app(WriteOffGroupLoanAction::class)->execute($disbursed->fresh(), $this->manager, 'Group disbanded');

    expect($writtenOff->status)->toBe(GroupLoanStatus::WrittenOff)
        ->and($writtenOff->outstanding_balance)->toBe(0)
        ->and($writtenOff->write_off_amount)->toBe($outstandingBeforeWriteOff) // full business loss, incl. interest
        ->and($writtenOff->write_off_reason)->toBe('Group disbanded')
        ->and($writtenOff->written_off_at)->not->toBeNull();

    $receivable = $writtenOff->receivableAccount;
    expect($receivable->refresh()->balance)->toBe(0);

    $badDebtExpense = app(ChartOfAccounts::class)->badDebtExpense($this->branch->company);
    expect($badDebtExpense->refresh()->balance)->toBe($principalOutstanding);

    // Accountability history is untouched — only the group's shared balance moved.
    foreach ($writtenOff->borrowers()->get() as $borrower) {
        expect($borrower->share_outstanding)->toBe($borrowerSharesBefore[$borrower->id]);
    }

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('refuses to write off a group loan that is not disbursed', function (): void {
    $groupLoan = applyGroupLoan($this->agent, $this->loanGroup->fresh(), $this->product);

    expect(fn () => app(WriteOffGroupLoanAction::class)->execute($groupLoan, $this->manager, 'no'))
        ->toThrow(ValidationException::class);
});

it('refuses to write off a group loan with no outstanding balance', function (): void {
    $groupLoan = applyGroupLoan($this->agent, $this->loanGroup->fresh(), $this->product);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);
    $borrower = $disbursed->borrowers()->orderBy('created_at')->first();
    app(RecordGroupLoanRepaymentAction::class)->execute($borrower, $disbursed->outstanding_balance, $this->manager);

    expect(fn () => app(WriteOffGroupLoanAction::class)->execute($disbursed->fresh(), $this->manager, 'no'))
        ->toThrow(ValidationException::class);
});
