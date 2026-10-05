<?php

namespace App\Actions\Ledger;

use App\Enums\AgentSummaryStatus;
use App\Enums\EntryStatus;
use App\Enums\GroupLoanStatus;
use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\TransactionType;
use App\Enums\WithdrawalStatus;
use App\Models\AgentDailySummary;
use App\Models\GroupLoan;
use App\Models\GroupLoanInstallment;
use App\Models\GroupLoanRepayment;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Ledger\LedgerService;
use App\Services\Loans\GroupLoanInstallmentAllocator;
use App\Services\Loans\LoanInstallmentAllocator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reverses a journal entry AND the domain state it changed, so the ledger
 * and the cached balances (savings balance, loan outstanding, installment
 * paid amounts) never drift apart. LedgerService::reverse() alone only
 * touches ledger lines and is not safe to call directly for these types.
 *
 * Loan repayments are undone by rebuilding the installment allocation from
 * scratch and replaying the remaining (non-reversed) repayments in order.
 * Types with wider side-effects (disbursement, restructure, write-off, group
 * susu flows, shares, interest) are refused.
 */
class ReverseJournalEntryAction
{
    /** @var array<int, TransactionType> */
    public const REVERSIBLE_TYPES = [
        TransactionType::Collection,
        TransactionType::Withdrawal,
        TransactionType::Remittance,
        TransactionType::Adjustment,
        TransactionType::Repayment,
        TransactionType::GroupLoanRepayment,
    ];

    public function __construct(
        private LedgerService $ledger,
        private LoanInstallmentAllocator $loanAllocator,
        private GroupLoanInstallmentAllocator $groupLoanAllocator,
    ) {}

    public static function supports(JournalEntry $entry): bool
    {
        return $entry->status !== EntryStatus::Reversed
            && in_array($entry->type, self::REVERSIBLE_TYPES, true);
    }

    public function execute(JournalEntry $entry, User $reversedBy, string $reason): JournalEntry
    {
        if (! in_array($entry->type, self::REVERSIBLE_TYPES, true)) {
            throw ValidationException::withMessages([
                'entry' => 'This entry type cannot be reversed here. Use the loan or group correction flow instead.',
            ]);
        }

        return DB::transaction(function () use ($entry, $reversedBy, $reason): JournalEntry {
            $locked = JournalEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === EntryStatus::Reversed) {
                throw ValidationException::withMessages(['entry' => 'This entry has already been reversed.']);
            }

            $reversal = match ($locked->type) {
                TransactionType::Collection => $this->reverseCollection($locked, $reversedBy, $reason),
                TransactionType::Withdrawal => $this->reverseWithdrawal($locked, $reversedBy, $reason),
                TransactionType::Repayment => $this->reverseLoanRepayment($locked, $reversedBy, $reason),
                TransactionType::GroupLoanRepayment => $this->reverseGroupLoanRepayment($locked, $reversedBy, $reason),
                default => $this->reverseLedgerOnly($locked, $reversedBy, $reason),
            };

            $entry->status = EntryStatus::Reversed;

            return $reversal;
        });
    }

    private function reverseLedgerOnly(JournalEntry $entry, User $reversedBy, string $reason): JournalEntry
    {
        try {
            return $this->ledger->reverse($entry, $reversedBy, $reason);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['entry' => $e->getMessage()]);
        }
    }

    /**
     * Undo a susu deposit: the deposit and its cycle commission (if one was
     * charged) are reversed, and the account loses the net amount it gained.
     */
    private function reverseCollection(JournalEntry $entry, User $reversedBy, string $reason): JournalEntry
    {
        $account = $this->lockSavingsAccount($entry);
        $amount = (int) ($entry->meta['amount'] ?? $entry->amount());

        $commission = JournalEntry::query()
            ->where('type', TransactionType::Commission)
            ->where('status', '!=', EntryStatus::Reversed)
            ->where('meta->collection_entry_id', $entry->id)
            ->lockForUpdate()
            ->first();
        $commissionAmount = $commission?->amount() ?? 0;
        $netCredited = $amount - $commissionAmount;

        if ($account->balance < $netCredited) {
            throw ValidationException::withMessages([
                'entry' => 'The account no longer holds this deposit (it has been withdrawn). Reverse the withdrawal first.',
            ]);
        }

        $reversal = $this->reverseLedgerOnly($entry, $reversedBy, $reason);
        if ($commission !== null) {
            $this->reverseLedgerOnly($commission, $reversedBy, $reason);
        }

        $units = $account->contribution_amount > 0 ? intdiv($amount, $account->contribution_amount) : 0;
        $account->forceFill([
            'balance' => $account->balance - $netCredited,
            'contributions_this_cycle' => max(0, $account->contributions_this_cycle - $units),
        ])->save();

        $this->untrackDailySummary($entry, $amount);

        return $reversal;
    }

    /**
     * Undo a paid withdrawal: the full requested amount (cash paid out plus
     * any early-withdrawal penalty) goes back into the account.
     */
    private function reverseWithdrawal(JournalEntry $entry, User $reversedBy, string $reason): JournalEntry
    {
        $requestId = $entry->meta['withdrawal_request_id'] ?? null;
        $request = $requestId !== null
            ? WithdrawalRequest::whereKey($requestId)->lockForUpdate()->first()
            : null;

        if ($request === null || $request->status !== WithdrawalStatus::Paid) {
            throw ValidationException::withMessages(['entry' => 'This withdrawal has no paid request to reverse.']);
        }

        $account = $this->lockSavingsAccount($entry);
        $reversal = $this->reverseLedgerOnly($entry, $reversedBy, $reason);

        $account->forceFill(['balance' => $account->balance + $request->amount])->save();
        $request->update(['status' => WithdrawalStatus::Reversed]);

        return $reversal;
    }

    private function reverseLoanRepayment(JournalEntry $entry, User $reversedBy, string $reason): JournalEntry
    {
        $loan = Loan::whereKey($entry->meta['loan_id'] ?? null)->lockForUpdate()->first();

        if ($loan === null || ! in_array($loan->status, [LoanStatus::Disbursed, LoanStatus::Closed], true)) {
            throw ValidationException::withMessages([
                'entry' => 'Only repayments on a running or repaid loan can be reversed.',
            ]);
        }

        $reversal = $this->reverseLedgerOnly($entry, $reversedBy, $reason);

        $loan->installments()->get()->each(function (LoanInstallment $installment) use ($loan): void {
            $installment->forceFill([
                'principal_paid' => 0,
                'interest_paid' => 0,
                'penalty_paid' => 0,
                'paid_at' => null,
                'status' => $this->unpaidStatus($installment->due_date->copy()->addDays($loan->grace_period_days)),
            ])->save();
        });

        $this->standingRepayments(TransactionType::Repayment, 'loan_id', $loan->id)
            ->each(fn (JournalEntry $repayment) => $this->loanAllocator->apply($loan, (int) $repayment->meta['amount']));

        $loan->forceFill([
            'outstanding_balance' => $loan->outstanding_balance + (int) $entry->meta['amount'],
            'status' => LoanStatus::Disbursed,
            'closed_at' => null,
        ])->save();

        return $reversal;
    }

    private function reverseGroupLoanRepayment(JournalEntry $entry, User $reversedBy, string $reason): JournalEntry
    {
        $groupLoan = GroupLoan::whereKey($entry->meta['group_loan_id'] ?? null)->lockForUpdate()->first();

        if ($groupLoan === null || ! in_array($groupLoan->status, [GroupLoanStatus::Active, GroupLoanStatus::Closed], true)) {
            throw ValidationException::withMessages([
                'entry' => 'Only repayments on an active or repaid group loan can be reversed.',
            ]);
        }

        $reversal = $this->reverseLedgerOnly($entry, $reversedBy, $reason);

        $groupLoan->installments()->get()->each(function (GroupLoanInstallment $installment): void {
            $installment->forceFill([
                'amount_paid' => 0,
                'paid_at' => null,
                'status' => $this->unpaidStatus($installment->due_date),
            ])->save();
        });

        GroupLoanRepayment::notReversed()
            ->where('group_loan_id', $groupLoan->id)
            ->orderBy('recorded_at')
            ->orderBy('created_at')
            ->get()
            ->each(fn (GroupLoanRepayment $repayment) => $this->groupLoanAllocator->apply($groupLoan, $repayment->amount));

        $groupLoan->forceFill([
            'outstanding_balance' => $groupLoan->outstanding_balance + (int) $entry->meta['amount'],
            'status' => GroupLoanStatus::Active,
            'closed_at' => null,
        ])->save();

        return $reversal;
    }

    private function lockSavingsAccount(JournalEntry $entry): SavingsAccount
    {
        $account = SavingsAccount::whereKey($entry->meta['savings_account_id'] ?? null)->lockForUpdate()->first();

        if ($account === null) {
            throw ValidationException::withMessages(['entry' => 'The savings account for this entry no longer exists.']);
        }

        return $account;
    }

    /**
     * @return Collection<int, JournalEntry>
     */
    private function standingRepayments(TransactionType $type, string $metaKey, string $id): Collection
    {
        return JournalEntry::query()
            ->where('type', $type)
            ->where('status', '!=', EntryStatus::Reversed)
            ->where("meta->{$metaKey}", $id)
            ->orderBy('recorded_at')
            ->orderBy('posted_at')
            ->get();
    }

    private function unpaidStatus(CarbonInterface $overdueAfter): InstallmentStatus
    {
        return today()->greaterThan($overdueAfter->copy()->startOfDay()) ? InstallmentStatus::Overdue : InstallmentStatus::Pending;
    }

    /**
     * Take the deposit off the agent's open day sheet. Submitted or
     * reconciled sheets are left alone: the variance then shows the
     * correction for the manager to see.
     */
    private function untrackDailySummary(JournalEntry $entry, int $amount): void
    {
        $summary = AgentDailySummary::query()
            ->where('agent_id', $entry->recorded_by)
            ->whereDate('summary_date', $entry->recorded_at->toDateString())
            ->where('status', AgentSummaryStatus::Open)
            ->lockForUpdate()
            ->first();

        if ($summary === null) {
            return;
        }

        $summary->forceFill([
            'collections_total' => max(0, $summary->collections_total - $amount),
            'collections_count' => max(0, $summary->collections_count - 1),
        ])->save();
    }
}
