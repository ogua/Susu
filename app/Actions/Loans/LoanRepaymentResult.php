<?php

namespace App\Actions\Loans;

use App\Models\JournalEntry;
use App\Models\Loan;

class LoanRepaymentResult
{
    public function __construct(
        public readonly JournalEntry $entry,
        public readonly Loan $loan,
        public readonly bool $duplicate,
    ) {}
}
