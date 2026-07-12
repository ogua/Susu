<?php

namespace App\Actions\Savings;

use App\Models\JournalEntry;
use App\Models\SavingsAccount;

class CollectionResult
{
    public function __construct(
        public readonly JournalEntry $entry,
        public readonly SavingsAccount $account,
        public readonly int $commissionAmount,
        public readonly bool $duplicate,
    ) {}
}
