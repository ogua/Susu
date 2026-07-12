<?php

namespace App\Policies;

use App\Models\SavingsAccount;
use App\Models\User;

class SavingsAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, SavingsAccount $account): bool
    {
        if ($user->company_id !== $account->company_id) {
            return false;
        }

        return $user->hasRole(['company_admin', 'branch_manager'])
            || ($user->hasRole('field_agent') && $account->agent_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function update(User $user, SavingsAccount $account): bool
    {
        return $user->company_id === $account->company_id
            && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function delete(User $user, SavingsAccount $account): bool
    {
        return $this->update($user, $account);
    }

    public function recordCollection(User $user, SavingsAccount $account): bool
    {
        if ($user->company_id !== $account->company_id) {
            return false;
        }

        return $user->hasRole(['company_admin', 'branch_manager'])
            || ($user->hasRole('field_agent') && $account->agent_id === $user->id);
    }
}
