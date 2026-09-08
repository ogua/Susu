<?php

namespace App\Services\Loans;

use App\Enums\LoanFrequency;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Spreads a group loan's principal into installments of a fixed periodic
 * amount — the "amount to be paid for the week/date" the client enters per
 * member. Every installment equals the periodic amount except the last, which
 * carries the remainder, so the schedule's principal always sums to exactly
 * the loan amount. There is no interest.
 *
 * The first installment falls on start_date itself (the client picks the
 * first collection date); each subsequent one steps forward by the frequency.
 *
 * Guards throw InvalidArgumentException (not ValidationException) so this
 * class stays usable from pure unit tests; the calling actions translate
 * those into user-facing validation errors.
 */
class PeriodicScheduleGenerator
{
    /** Refuse to build a schedule longer than this — a runaway daily plan is a data-entry mistake, not a loan. */
    public const MAX_PERIODS = 730;

    /**
     * @return array<int, ScheduledInstallment>
     */
    public function generate(
        int $principal,
        int $periodicAmount,
        LoanFrequency $frequency,
        Carbon $startDate,
    ): array {
        $count = $this->periodCount($principal, $periodicAmount);

        $schedule = [];
        $dueDate = $startDate->copy();

        for ($sequence = 1; $sequence <= $count; $sequence++) {
            $isLast = $sequence === $count;
            $amount = $isLast
                ? $principal - ($periodicAmount * ($count - 1))
                : $periodicAmount;

            $schedule[] = new ScheduledInstallment($sequence, $dueDate->copy(), $amount, 0);

            $dueDate = $frequency->addPeriod($dueDate);
        }

        return $schedule;
    }

    /** How many installments the principal spreads into at this periodic amount. */
    public function periodCount(int $principal, int $periodicAmount): int
    {
        if ($principal <= 0) {
            throw new InvalidArgumentException('The loan amount must be greater than zero.');
        }
        if ($periodicAmount <= 0) {
            throw new InvalidArgumentException('The periodic amount must be greater than zero.');
        }

        if ($periodicAmount >= $principal) {
            return 1;
        }

        $count = intdiv($principal, $periodicAmount) + ($principal % $periodicAmount > 0 ? 1 : 0);

        if ($count > self::MAX_PERIODS) {
            throw new InvalidArgumentException(
                'This schedule would run for over '.self::MAX_PERIODS.' payments — increase the periodic amount or use a longer frequency.'
            );
        }

        return $count;
    }
}
