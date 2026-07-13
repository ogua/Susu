<?php

namespace App\Services\Loans;

/** Explains *why* a customer doesn't qualify, not just whether they do — surfaced as-is in the API/Filament panel. */
class LoanEligibilityResult
{
    /**
     * @param  array<int, string>  $reasons  empty when eligible
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly array $reasons,
    ) {}
}
