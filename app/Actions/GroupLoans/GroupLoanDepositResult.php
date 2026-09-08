<?php

namespace App\Actions\GroupLoans;

use App\Models\GroupLoan;
use App\Models\GroupLoanDeposit;
use App\Models\JournalEntry;

class GroupLoanDepositResult
{
    public function __construct(
        public readonly ?JournalEntry $entry,
        public readonly GroupLoan $groupLoan,
        public readonly GroupLoanDeposit $deposit,
        public readonly bool $duplicate,
    ) {}
}
