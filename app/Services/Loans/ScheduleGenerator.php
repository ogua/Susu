<?php

namespace App\Services\Loans;

use App\Enums\InterestMethod;
use App\Enums\LoanFrequency;
use Illuminate\Support\Carbon;

/**
 * Builds the full repayment schedule for a loan. Principal is split as
 * equally as possible across periods, with any rounding remainder absorbed
 * into the *last* installment — so the schedule's principal always sums to
 * exactly the loan amount, never a pesewa more or less.
 *
 * Due dates step one period from disbursement, unless a first due date is
 * given (the date chosen on the application), in which case the first
 * installment falls on it and the rest step from there. Dates step from the
 * previous due date (not the anchor) — the desktop's ported generator does the
 * same, so month-end schedules stay identical across platforms.
 */
class ScheduleGenerator
{
    public function __construct(private InterestCalculator $interest) {}

    /**
     * @return array<int, ScheduledInstallment>
     */
    public function generate(
        int $principal,
        int $rateBasisPoints,
        int $termPeriods,
        InterestMethod $method,
        LoanFrequency $frequency,
        Carbon $disbursedAt,
        ?Carbon $firstDueDate = null,
    ): array {
        $principalPerPeriod = intdiv($principal, $termPeriods);
        $lastPeriodRemainder = $principal - ($principalPerPeriod * $termPeriods);

        $schedule = [];
        $balance = $principal;
        $dueDate = null;

        for ($period = 1; $period <= $termPeriods; $period++) {
            $dueDate = match (true) {
                $dueDate !== null => $frequency->addPeriod($dueDate),
                $firstDueDate !== null => $firstDueDate->copy(),
                default => $frequency->addPeriod($disbursedAt),
            };
            $periodPrincipal = $principalPerPeriod + ($period === $termPeriods ? $lastPeriodRemainder : 0);
            $periodInterest = $this->interest->periodInterest($balance, $principal, $rateBasisPoints, $method);

            $schedule[] = new ScheduledInstallment($period, $dueDate->copy(), $periodPrincipal, $periodInterest);

            $balance -= $periodPrincipal;
        }

        return $schedule;
    }

    /** Convenience: total interest across the whole schedule, before any installments are persisted. */
    public function totalInterest(
        int $principal,
        int $rateBasisPoints,
        int $termPeriods,
        InterestMethod $method,
        LoanFrequency $frequency,
        Carbon $disbursedAt,
    ): int {
        $schedule = $this->generate($principal, $rateBasisPoints, $termPeriods, $method, $frequency, $disbursedAt);

        return array_sum(array_map(fn (ScheduledInstallment $installment): int => $installment->interestDue, $schedule));
    }
}
