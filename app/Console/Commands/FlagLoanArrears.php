<?php

namespace App\Console\Commands;

use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\LoanInstallment;
use Illuminate\Console\Command;

/**
 * Flags installments overdue past their grace period and accrues a one-time
 * late penalty (never re-charged on subsequent runs — see the penalty_due =
 * 0 guard below, which is what makes this idempotent to re-run daily).
 * Keeps loan.outstanding_balance in sync with the new penalty so it still
 * equals the sum of every installment's remaining() — the invariant
 * RecordLoanRepaymentAction relies on.
 */
class FlagLoanArrears extends Command
{
    protected $signature = 'loans:flag-arrears';

    protected $description = 'Flags loan installments overdue past their grace period and accrues late penalties.';

    public function handle(): int
    {
        $flagged = 0;

        LoanInstallment::query()
            ->whereIn('status', [InstallmentStatus::Pending, InstallmentStatus::PartiallyPaid])
            ->where('penalty_due', 0)
            ->whereHas('loan', fn ($query) => $query->where('status', LoanStatus::Disbursed))
            ->with('loan')
            ->chunkById(200, function ($installments) use (&$flagged): void {
                foreach ($installments as $installment) {
                    $loan = $installment->loan;
                    $graceDeadline = $installment->due_date->copy()->addDays($loan->grace_period_days);

                    if (now()->lessThanOrEqualTo($graceDeadline)) {
                        continue;
                    }

                    $overdueAmount = $installment->remainingPrincipal() + $installment->remainingInterest();
                    $penalty = intdiv($overdueAmount * $loan->penalty_rate_bps, 10_000);

                    $installment->forceFill([
                        'status' => InstallmentStatus::Overdue,
                        'penalty_due' => $penalty,
                    ])->save();

                    if ($penalty > 0) {
                        Loan::whereKey($loan->id)->increment('outstanding_balance', $penalty);
                    }

                    $flagged++;
                }
            });

        $this->info("Flagged {$flagged} installment(s) as overdue.");

        return self::SUCCESS;
    }
}
