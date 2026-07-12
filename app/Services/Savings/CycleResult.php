<?php

namespace App\Services\Savings;

/**
 * Result of simulating a deposit's contribution units against an account's
 * susu cycle: the commission owed and where the counters land.
 */
class CycleResult
{
    public function __construct(
        public readonly int $commissionAmount,
        public readonly int $cyclesStarted,
        public readonly int $newContributionsThisCycle,
        public readonly int $newCycleNumber,
    ) {}
}
