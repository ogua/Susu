<?php

namespace App\Actions\Loans;

use App\Enums\ClientOrigin;
use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies a repayment across outstanding installments oldest-first —
 * penalty, then interest, then principal within each installment (clearing
 * the punitive charge and accrued revenue before principal is considered
 * repaid). Idempotent on client_reference — a replayed op (e.g. an offline
 * sync retry) can never double-post.
 */
class RecordLoanRepaymentAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
    ) {}

    public function execute(
        Loan $loan,
        int $amount,
        User $recordedBy,
        ?string $clientReference = null,
        ?CarbonInterface $recordedAt = null,
        ClientOrigin $origin = ClientOrigin::Web,
        PaymentMethod $paymentMethod = PaymentMethod::Cash,
    ): LoanRepaymentResult {
        if ($clientReference !== null) {
            $existing = $this->ledger->findByClientReference($clientReference);
            if ($existing !== null) {
                return new LoanRepaymentResult($existing, $loan, duplicate: true);
            }
        }

        if ($loan->status !== LoanStatus::Disbursed) {
            throw ValidationException::withMessages(['status' => 'Only disbursed loans can receive repayments.']);
        }
        if ($amount <= 0 || $amount > $loan->outstanding_balance) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be positive and cannot exceed the outstanding balance.',
            ]);
        }

        return DB::transaction(function () use ($loan, $amount, $recordedBy, $clientReference, $recordedAt, $origin, $paymentMethod): LoanRepaymentResult {
            /** @var Loan $lockedLoan */
            $lockedLoan = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();

            [$principalApplied, $interestApplied, $penaltyApplied] = $this->applyToInstallments($lockedLoan, $amount);

            $lines = [
                ['account' => $this->cashAccount($lockedLoan, $paymentMethod), 'debit' => $amount],
            ];
            if ($principalApplied > 0) {
                $lines[] = ['account' => $lockedLoan->receivableAccount, 'credit' => $principalApplied];
            }
            if ($interestApplied > 0) {
                $lines[] = ['account' => $this->chart->loanInterestIncome($lockedLoan->company), 'credit' => $interestApplied];
            }
            if ($penaltyApplied > 0) {
                $lines[] = ['account' => $this->chart->loanPenaltyIncome($lockedLoan->company), 'credit' => $penaltyApplied];
            }

            $entry = $this->ledger->post(new EntryData(
                company: $lockedLoan->company,
                type: TransactionType::Repayment,
                lines: $lines,
                branch: $lockedLoan->branch,
                paymentMethod: $paymentMethod,
                origin: $origin,
                recordedBy: $recordedBy,
                recordedAt: $recordedAt ?? now(),
                clientReference: $clientReference,
                description: "Loan repayment {$lockedLoan->loan_number}",
                meta: [
                    'customer_id' => $lockedLoan->customer_id,
                    'loan_id' => $lockedLoan->id,
                    'amount' => $amount,
                ],
            ));

            $newOutstanding = $lockedLoan->outstanding_balance - $amount;
            $lockedLoan->forceFill([
                'outstanding_balance' => $newOutstanding,
                'status' => $newOutstanding <= 0 ? LoanStatus::Closed : $lockedLoan->status,
                'closed_at' => $newOutstanding <= 0 ? now() : null,
            ])->save();

            return new LoanRepaymentResult($entry, $lockedLoan->fresh(), duplicate: false);
        });
    }

    /**
     * @return array{0: int, 1: int, 2: int} [principalApplied, interestApplied, penaltyApplied]
     */
    private function applyToInstallments(Loan $loan, int $amount): array
    {
        $remaining = $amount;
        $principalApplied = 0;
        $interestApplied = 0;
        $penaltyApplied = 0;

        $installments = $loan->installments()
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

            // Penalty first (it's the punitive charge for lateness), then
            // interest, then principal — matches totalDue()'s composition so
            // the three portions always sum to exactly $installmentTotal.
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
                // Stays visibly overdue through a partial payment rather than
                // reverting to PartiallyPaid — it's still late until settled.
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

    private function cashAccount(Loan $loan, PaymentMethod $method): LedgerAccount
    {
        return match ($method) {
            PaymentMethod::MobileMoney => $this->chart->momoClearing($loan->company),
            default => $this->chart->branchCash($loan->branch),
        };
    }
}
