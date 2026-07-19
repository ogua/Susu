<?php

namespace App\Actions\GroupLoans;

use App\Enums\ClientOrigin;
use App\Enums\GroupLoanStatus;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\GroupLoan;
use App\Models\GroupLoanBorrower;
use App\Models\GroupLoanRepayment;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records one member's repayment against the group's shared debt — blends
 * RecordGroupContributionAction's "attribute an individual payment to a
 * collective total" pattern with RecordLoanRepaymentAction's installment
 * allocation. Validated against the SHARED outstanding balance, not the
 * paying member's own share: under joint & several liability, any member may
 * pay down more than their own share to cover a delinquent co-member — that's
 * expected, not an error. The borrower's own share_outstanding is
 * accountability bookkeeping only; it floors at 0 and never reallocates onto
 * another member's share. Idempotent on client_reference.
 */
class RecordGroupLoanRepaymentAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
    ) {}

    public function execute(
        GroupLoanBorrower $borrower,
        int $amount,
        User $recordedBy,
        ?string $clientReference = null,
        ?CarbonInterface $recordedAt = null,
        ClientOrigin $origin = ClientOrigin::Web,
        PaymentMethod $paymentMethod = PaymentMethod::Cash,
    ): GroupLoanRepaymentResult {
        if ($clientReference !== null) {
            $existing = GroupLoanRepayment::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                return new GroupLoanRepaymentResult(
                    $existing->journalEntry,
                    $existing->groupLoan,
                    $existing->borrower,
                    $existing,
                    duplicate: true,
                );
            }
        }

        $groupLoan = $borrower->groupLoan;

        if ($groupLoan->status !== GroupLoanStatus::Disbursed) {
            throw ValidationException::withMessages(['status' => 'Only disbursed group loans can receive repayments.']);
        }
        if ($amount <= 0 || $amount > $groupLoan->outstanding_balance) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be positive and cannot exceed the group loan\'s outstanding balance.',
            ]);
        }

        return DB::transaction(function () use ($groupLoan, $borrower, $amount, $recordedBy, $clientReference, $recordedAt, $origin, $paymentMethod): GroupLoanRepaymentResult {
            /** @var GroupLoan $lockedGroupLoan */
            $lockedGroupLoan = GroupLoan::whereKey($groupLoan->id)->lockForUpdate()->firstOrFail();

            [$principalApplied, $interestApplied, $penaltyApplied] = $this->applyToInstallments($lockedGroupLoan, $amount);

            $lines = [
                ['account' => $this->cashAccount($lockedGroupLoan, $paymentMethod), 'debit' => $amount],
            ];
            if ($principalApplied > 0) {
                $lines[] = ['account' => $lockedGroupLoan->receivableAccount, 'credit' => $principalApplied];
            }
            if ($interestApplied > 0) {
                $lines[] = ['account' => $this->chart->loanInterestIncome($lockedGroupLoan->company), 'credit' => $interestApplied];
            }
            if ($penaltyApplied > 0) {
                $lines[] = ['account' => $this->chart->loanPenaltyIncome($lockedGroupLoan->company), 'credit' => $penaltyApplied];
            }

            $entry = $this->ledger->post(new EntryData(
                company: $lockedGroupLoan->company,
                type: TransactionType::GroupLoanRepayment,
                lines: $lines,
                branch: $lockedGroupLoan->branch,
                paymentMethod: $paymentMethod,
                origin: $origin,
                recordedBy: $recordedBy,
                recordedAt: $recordedAt ?? now(),
                clientReference: $clientReference,
                description: "Group loan repayment {$lockedGroupLoan->loan_number}",
                meta: [
                    'customer_id' => $borrower->customer_id,
                    'group_loan_id' => $lockedGroupLoan->id,
                    'amount' => $amount,
                ],
            ));

            $repayment = GroupLoanRepayment::create([
                'group_loan_id' => $lockedGroupLoan->id,
                'group_loan_borrower_id' => $borrower->id,
                'journal_entry_id' => $entry->id,
                'recorded_by' => $recordedBy->id,
                'amount' => $amount,
                'recorded_at' => $recordedAt ?? now(),
                'client_reference' => $clientReference,
            ]);

            $borrower->forceFill([
                'share_outstanding' => max(0, $borrower->share_outstanding - $amount),
            ])->save();

            $newOutstanding = $lockedGroupLoan->outstanding_balance - $amount;
            $lockedGroupLoan->forceFill([
                'outstanding_balance' => $newOutstanding,
                'status' => $newOutstanding <= 0 ? GroupLoanStatus::Closed : $lockedGroupLoan->status,
                'closed_at' => $newOutstanding <= 0 ? now() : null,
            ])->save();

            return new GroupLoanRepaymentResult($entry, $lockedGroupLoan->fresh(), $borrower->fresh(), $repayment, duplicate: false);
        });
    }

    /**
     * @return array{0: int, 1: int, 2: int} [principalApplied, interestApplied, penaltyApplied]
     */
    private function applyToInstallments(GroupLoan $groupLoan, int $amount): array
    {
        $remaining = $amount;
        $principalApplied = 0;
        $interestApplied = 0;
        $penaltyApplied = 0;

        $installments = $groupLoan->installments()
            ->whereIn('status', [InstallmentStatus::Pending, InstallmentStatus::PartiallyPaid, InstallmentStatus::Overdue])
            ->get();

        foreach ($installments as $installment) {
            if ($remaining <= 0) {
                break;
            }

            $installmentTotal = min($installment->remaining(), $remaining);
            if ($installmentTotal <= 0) {
                continue;
            }

            $penaltyPortion = min($installment->remainingPenalty(), $installmentTotal);
            $interestPortion = min($installment->remainingInterest(), $installmentTotal - $penaltyPortion);
            $principalPortion = min($installment->remainingPrincipal(), $installmentTotal - $penaltyPortion - $interestPortion);

            $wasOverdue = $installment->status === InstallmentStatus::Overdue;

            $installment->forceFill([
                'penalty_paid' => $installment->penalty_paid + $penaltyPortion,
                'interest_paid' => $installment->interest_paid + $interestPortion,
                'principal_paid' => $installment->principal_paid + $principalPortion,
            ]);
            $installment->status = match (true) {
                $installment->amountPaid() >= $installment->totalDue() => InstallmentStatus::Paid,
                $wasOverdue => InstallmentStatus::Overdue,
                default => InstallmentStatus::PartiallyPaid,
            };
            if ($installment->status === InstallmentStatus::Paid) {
                $installment->paid_at = now();
            }
            $installment->save();

            $penaltyApplied += $penaltyPortion;
            $principalApplied += $principalPortion;
            $interestApplied += $interestPortion;
            $remaining -= $installmentTotal;
        }

        return [$principalApplied, $interestApplied, $penaltyApplied];
    }

    private function cashAccount(GroupLoan $groupLoan, PaymentMethod $method): LedgerAccount
    {
        return match ($method) {
            PaymentMethod::MobileMoney => $this->chart->momoClearing($groupLoan->company),
            default => $this->chart->branchCash($groupLoan->branch),
        };
    }
}
