<?php

namespace App\Actions\GroupLoans;

use App\Models\GroupLoan;
use App\Models\GroupLoanBorrower;
use App\Models\GroupLoanRepayment;
use App\Models\JournalEntry;

class GroupLoanRepaymentResult
{
    public function __construct(
        public readonly JournalEntry $entry,
        public readonly GroupLoan $groupLoan,
        public readonly ?GroupLoanBorrower $borrower,
        public readonly ?GroupLoanRepayment $repayment,
        public readonly bool $duplicate,
    ) {}
}
