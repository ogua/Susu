<?php

namespace App\Actions\Loans;

use App\Enums\GroupLoanStatus;
use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Models\GroupLoan;
use App\Models\GroupLoanInstallment;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fixes a loan's repayment dates. Amounts, payments and the ledger are never
 * touched — only due dates (and the overdue flag that depends on them).
 *
 * - Fully paid installments keep their dates (they're history).
 * - Every unpaid installment is re-dated in sequence, one period apart:
 *   - from $firstDueDate when given (e.g. the customer travelled and the
 *     officer agreed to restart collections next Monday), or
 *   - otherwise from the loan's own rule — the chosen first repayment date,
 *     else one period after disbursement (individual) / the start date
 *     (group) — which repairs dates that were generated or edited wrongly.
 * - An overdue installment moved into the future is un-flagged (any penalty
 *   already accrued stays owed); newly late ones are flagged by the usual
 *   loans:flag-arrears run, with its grace period and penalty.
 *
 * Works for both individual loans (Loan) and group member loans (GroupLoan).
 */
class RecalculateRepaymentScheduleAction
{
    /**
     * @return array{rescheduled: int, first_due_date: ?string, last_due_date: ?string}
     */
    public function execute(Loan|GroupLoan $loan, User $recalculatedBy, ?CarbonInterface $firstDueDate = null, ?string $reason = null): array
    {
        $this->assertRecalculable($loan, $recalculatedBy);

        return DB::transaction(function () use ($loan, $recalculatedBy, $firstDueDate, $reason): array {
            $installments = $loan->installments()->lockForUpdate()->get();
            $frequency = $loan->repayment_frequency;

            $unpaid = $installments->filter(fn (LoanInstallment|GroupLoanInstallment $installment): bool => $installment->status !== InstallmentStatus::Paid);
            if ($unpaid->isEmpty()) {
                throw ValidationException::withMessages(['schedule' => 'Every installment is already paid — there is nothing to reschedule.']);
            }

            $before = $unpaid->mapWithKeys(fn ($installment): array => [$installment->sequence => $installment->due_date->toDateString()])->all();
            $today = today();
            $dueDate = null;

            foreach ($unpaid->sortBy('sequence') as $installment) {
                $dueDate = match (true) {
                    $dueDate !== null => $frequency->addPeriod($dueDate),
                    $firstDueDate !== null => Carbon::parse($firstDueDate)->startOfDay(),
                    default => $this->ruleDueDate($loan, $installment->sequence),
                };

                $installment->forceFill([
                    'due_date' => $dueDate->toDateString(),
                    'status' => $this->statusFor($installment, $dueDate, $today),
                ])->save();
            }

            $after = $unpaid->mapWithKeys(fn ($installment): array => [$installment->sequence => $installment->due_date->toDateString()])->all();

            if ($loan instanceof GroupLoan && $unpaid->first()->sequence === 1) {
                $loan->forceFill(['start_date' => $after[1]])->save();
            }

            activity($loan instanceof GroupLoan ? 'group_loan' : 'loan')
                ->performedOn($loan)
                ->causedBy($recalculatedBy)
                ->event('schedule_recalculated')
                ->withProperties(['reason' => $reason, 'old' => $before, 'attributes' => $after])
                ->log('Repayment schedule recalculated'.($reason ? ": {$reason}" : ''));

            return [
                'rescheduled' => count($after),
                'first_due_date' => reset($after) ?: null,
                'last_due_date' => end($after) ?: null,
            ];
        });
    }

    /** Where installment N falls under the loan's original dating rule. */
    private function ruleDueDate(Loan|GroupLoan $loan, int $sequence): CarbonInterface
    {
        $frequency = $loan->repayment_frequency;

        if ($loan instanceof GroupLoan) {
            $anchor = $loan->start_date->copy();
            $steps = $sequence - 1;
        } elseif ($loan->first_repayment_date !== null && $loan->first_repayment_date->gte($loan->disbursed_at->copy()->startOfDay())) {
            $anchor = $loan->first_repayment_date->copy();
            $steps = $sequence - 1;
        } else {
            $anchor = $loan->disbursed_at->copy()->startOfDay();
            $steps = $sequence;
        }

        // Step period by period (not addPeriod($anchor, $steps)) to match how
        // the schedule generators build dates — month-end handling stays identical.
        for ($step = 0; $step < $steps; $step++) {
            $anchor = $frequency->addPeriod($anchor);
        }

        return $anchor;
    }

    private function statusFor(LoanInstallment|GroupLoanInstallment $installment, CarbonInterface $dueDate, CarbonInterface $today): InstallmentStatus
    {
        $paid = $installment instanceof GroupLoanInstallment
            ? $installment->amount_paid
            : $installment->principal_paid + $installment->interest_paid + $installment->penalty_paid;

        // Only an installment that is already overdue and still past due keeps
        // the flag. Newly late ones are left to loans:flag-arrears, which owns
        // the grace period and penalty accrual that come with flagging.
        return match (true) {
            $installment->status === InstallmentStatus::Overdue && $dueDate->lt($today) => InstallmentStatus::Overdue,
            $paid > 0 => InstallmentStatus::PartiallyPaid,
            default => InstallmentStatus::Pending,
        };
    }

    private function assertRecalculable(Loan|GroupLoan $loan, User $user): void
    {
        $isLive = $loan instanceof GroupLoan
            ? $loan->status === GroupLoanStatus::Active
            : $loan->status === LoanStatus::Disbursed;

        if (! $isLive) {
            throw ValidationException::withMessages(['status' => 'Only a running (disbursed/active) loan has a schedule to recalculate.']);
        }
        if ($user->company_id !== $loan->company_id || ! $user->hasRole(['branch_manager', 'company_admin'])) {
            throw ValidationException::withMessages(['schedule' => 'Only managers can recalculate a repayment schedule.']);
        }
    }
}
