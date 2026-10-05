<?php

namespace App\Services\Loans;

use App\Enums\InstallmentStatus;
use App\Models\Loan;

/**
 * Applies a payment across an individual loan's installments oldest-first —
 * penalty, then interest, then principal within each installment (clearing
 * the punitive charge and accrued revenue before principal is considered
 * repaid). Shared by the repayment path and the reversal replay.
 */
class LoanInstallmentAllocator
{
    /**
     * @return array{0: int, 1: int, 2: int} [principalApplied, interestApplied, penaltyApplied]
     */
    public function apply(Loan $loan, int $amount): array
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
}
