<?php

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;

/** Journal entries are immutable — the ledger only supports view and reversal. */
class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $user->company_id === $entry->company_id;
    }

    public function reverse(User $user, JournalEntry $entry): bool
    {
        return $user->company_id === $entry->company_id
            && $user->hasRole(['company_admin', 'branch_manager']);
    }
}
