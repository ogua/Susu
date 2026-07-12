<?php

namespace App\Policies;

use App\Models\LedgerAccount;
use App\Models\User;

/** The chart of accounts is company-wide and system-managed — view only. */
class LedgerAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function view(User $user, LedgerAccount $account): bool
    {
        return $user->company_id === $account->company_id
            && $user->hasRole(['company_admin', 'branch_manager']);
    }
}
