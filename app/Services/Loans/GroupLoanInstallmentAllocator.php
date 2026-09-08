<?php

namespace App\Services\Loans;

use App\Enums\InstallmentStatus;
use App\Models\GroupLoan;

/**
 * Applies a payment across a group loan's installments oldest-first. Group
 * loan installments are pure principal, so there is no penalty/interest split
 * to worry about — each installment just fills toward its amount_due. Shared
 * by the cash-repayment and deposit-offset paths.
 */
class GroupLoanInstallmentAllocator
{
    /**
     * @return int the amount actually applied (never more than the sum of the installments' remaining balances)
     */
    public function apply(GroupLoan $groupLoan, int $amount): int
    {
        $remaining = $amount;
        $applied = 0;

        $installments = $groupLoan->installments()
            ->whereIn('status', [InstallmentStatus::Pending, InstallmentStatus::PartiallyPaid, InstallmentStatus::Overdue])
            ->orderBy('sequence')
            ->get();

        foreach ($installments as $installment) {
            if ($remaining <= 0) {
                break;
            }

            $portion = min($installment->remaining(), $remaining);
            if ($portion <= 0) {
                continue;
            }

            $wasOverdue = $installment->status === InstallmentStatus::Overdue;

            $installment->amount_paid += $portion;
            $installment->status = match (true) {
                $installment->amount_paid >= $installment->amount_due => InstallmentStatus::Paid,
                $wasOverdue => InstallmentStatus::Overdue,
                default => InstallmentStatus::PartiallyPaid,
            };
            if ($installment->status === InstallmentStatus::Paid) {
                $installment->paid_at = now();
            }
            $installment->save();

            $applied += $portion;
            $remaining -= $portion;
        }

        return $applied;
    }
}
