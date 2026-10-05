<?php

use App\Actions\Ledger\ReverseJournalEntryAction;
use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Loans\RecordLoanRepaymentAction;
use App\Actions\Savings\DecideWithdrawalAction;
use App\Actions\Savings\PayWithdrawalAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Actions\Savings\RequestWithdrawalAction;
use App\Enums\EntryStatus;
use App\Enums\LoanStatus;
use App\Enums\TransactionType;
use App\Enums\WithdrawalStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->customer = Customer::factory()->forBranch($this->branch)->create();

    $product = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
    ]);
    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);
});

function reverseEntry(JournalEntry $entry, User $by): JournalEntry
{
    return app(ReverseJournalEntryAction::class)->execute($entry, $by, 'Posted in error');
}

it('reverses a collection and takes the deposit back off the savings balance', function (): void {
    $collect = app(RecordCollectionAction::class);
    $collect->execute($this->agent, $this->account, 500);
    $before = $this->account->refresh()->balance;
    $contributionsBefore = $this->account->contributions_this_cycle;

    $result = $collect->execute($this->agent, $this->account, 1000);
    expect($this->account->refresh()->balance)->toBeGreaterThan($before);

    reverseEntry($result->entry, $this->manager);

    expect($this->account->refresh()->balance)->toBe($before)
        ->and($this->account->contributions_this_cycle)->toBe($contributionsBefore)
        ->and($result->entry->refresh()->status)->toBe(EntryStatus::Reversed);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('also reverses the cycle commission charged with a collection', function (): void {
    $result = app(RecordCollectionAction::class)->execute($this->agent, $this->account, 1500);

    reverseEntry($result->entry, $this->manager);

    expect($this->account->refresh()->balance)->toBe(0)
        ->and(JournalEntry::where('type', TransactionType::Commission)->where('status', '!=', EntryStatus::Reversed)->count())->toBe(0);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('refuses to reverse a collection whose money has already been withdrawn', function (): void {
    $result = app(RecordCollectionAction::class)->execute($this->agent, $this->account, 1000);
    $this->account->refresh();

    $request = app(RequestWithdrawalAction::class)->execute($this->agent, $this->account, $this->account->balance);
    app(DecideWithdrawalAction::class)->approve($this->manager, $request);
    app(PayWithdrawalAction::class)->execute($this->manager, $request->refresh());

    expect(fn () => reverseEntry($result->entry, $this->manager))->toThrow(ValidationException::class);
    expect($result->entry->refresh()->status)->toBe(EntryStatus::Completed);
});

it('reverses a paid withdrawal and puts the money back', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 2000);
    $before = $this->account->refresh()->balance;

    $request = app(RequestWithdrawalAction::class)->execute($this->agent, $this->account, 500);
    app(DecideWithdrawalAction::class)->approve($this->manager, $request);
    app(PayWithdrawalAction::class)->execute($this->manager, $request->refresh());
    expect($this->account->refresh()->balance)->toBe($before - 500);

    reverseEntry(JournalEntry::find($request->refresh()->paid_entry_id), $this->manager);

    expect($this->account->refresh()->balance)->toBe($before)
        ->and($request->refresh()->status)->toBe(WithdrawalStatus::Reversed);

    $this->artisan('ledger:verify-balances')->assertSuccessful();
});

it('reverses a loan repayment by replaying the remaining repayments', function (): void {
    $product = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'interest_method' => 'flat',
        'interest_rate_bps' => 300,
        'term_period_count' => 3,
        'repayment_frequency' => 'monthly',
        'origination_fee_amount' => 0,
        'min_amount' => 100_00,
        'max_amount' => 1_000_00,
    ]);
    $loan = app(ApplyForLoanAction::class)->execute(
        submittedBy: $this->agent,
        customer: $this->customer,
        product: $product,
        requestedAmount: 300_00,
    );
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $loan = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);
    $outstanding = $loan->outstanding_balance;

    $repay = app(RecordLoanRepaymentAction::class);
    $first = $repay->execute($loan->fresh(), 50_00, $this->agent);
    $repay->execute($loan->fresh(), 20_00, $this->agent);

    reverseEntry($first->entry, $this->manager);

    $loan->refresh();
    $paidOnInstallments = $loan->installments->sum(fn ($installment) => $installment->amountPaid());

    expect($loan->outstanding_balance)->toBe($outstanding - 20_00)
        ->and($paidOnInstallments)->toBe(20_00)
        ->and($loan->status)->toBe(LoanStatus::Disbursed);
});

it('reopens a fully repaid loan when its repayment is reversed', function (): void {
    $product = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'interest_method' => 'flat',
        'interest_rate_bps' => 300,
        'term_period_count' => 3,
        'repayment_frequency' => 'monthly',
        'origination_fee_amount' => 0,
        'min_amount' => 100_00,
        'max_amount' => 1_000_00,
    ]);
    $loan = app(ApplyForLoanAction::class)->execute(
        submittedBy: $this->agent,
        customer: $this->customer,
        product: $product,
        requestedAmount: 300_00,
    );
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $loan = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);
    $outstanding = $loan->outstanding_balance;

    $payoff = app(RecordLoanRepaymentAction::class)->execute($loan->fresh(), $outstanding, $this->agent);
    expect($loan->refresh()->status)->toBe(LoanStatus::Closed);

    reverseEntry($payoff->entry, $this->manager);

    expect($loan->refresh()->status)->toBe(LoanStatus::Disbursed)
        ->and($loan->outstanding_balance)->toBe($outstanding)
        ->and($loan->closed_at)->toBeNull();
});

it('refuses to reverse the same entry twice', function (): void {
    $result = app(RecordCollectionAction::class)->execute($this->agent, $this->account, 1000);

    reverseEntry($result->entry, $this->manager);

    expect(fn () => reverseEntry($result->entry->refresh(), $this->manager))
        ->toThrow(ValidationException::class, 'This entry has already been reversed.');
});

it('refuses entry types whose side-effects it cannot undo', function (): void {
    $entry = JournalEntry::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'type' => TransactionType::Disbursement,
    ]);

    expect(ReverseJournalEntryAction::supports($entry))->toBeFalse();
    expect(fn () => reverseEntry($entry, $this->manager))->toThrow(ValidationException::class);
});

it('reports a savings balance that no longer matches its ledger account', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 1000);
    SavingsAccount::whereKey($this->account->id)->increment('balance', 1);

    $this->artisan('ledger:verify-balances')->assertFailed();
});
