<?php

namespace App\Services\Loans;

use Illuminate\Support\Carbon;

/** One row of a generated repayment schedule, before it's persisted as a LoanInstallment. */
class ScheduledInstallment
{
    public function __construct(
        public readonly int $sequence,
        public readonly Carbon $dueDate,
        public readonly int $principalDue,
        public readonly int $interestDue,
    ) {}

    public function totalDue(): int
    {
        return $this->principalDue + $this->interestDue;
    }
}
