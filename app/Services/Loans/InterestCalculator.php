<?php

namespace App\Services\Loans;

use App\Enums\InterestMethod;

/**
 * Pure per-period interest math — no persistence, no schedule/date logic
 * (that's ScheduleGenerator, which calls this once per period). Kept
 * separate so the two rate methods can be tested in complete isolation.
 */
class InterestCalculator
{
    /**
     * Interest owed for a single period.
     *
     * Flat: the rate applies to the original principal every period, so this
     * ignores the outstanding balance entirely — every installment's interest
     * is identical.
     *
     * Reducing balance: the rate applies to whatever principal is still
     * outstanding *before* this period's principal portion is deducted, so
     * interest shrinks period over period as the loan is paid down.
     */
    public function periodInterest(
        int $outstandingBalance,
        int $originalPrincipal,
        int $rateBasisPoints,
        InterestMethod $method,
    ): int {
        return match ($method) {
            InterestMethod::Flat => intdiv($originalPrincipal * $rateBasisPoints, 10_000),
            InterestMethod::ReducingBalance => intdiv($outstandingBalance * $rateBasisPoints, 10_000),
        };
    }
}
