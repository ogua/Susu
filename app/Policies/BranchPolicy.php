<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin') || $user->company_id !== null;
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->hasRole('super_admin')
            || ($user->company_id === $branch->company_id
                && ($user->hasRole(['company_admin', 'branch_manager', 'field_agent'])));
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['super_admin', 'company_admin']);
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->hasRole('super_admin')
            || ($user->hasRole('company_admin') && $user->company_id === $branch->company_id);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->hasRole('super_admin')
            || ($user->hasRole('company_admin') && $user->company_id === $branch->company_id);
    }

    public function restore(User $user, Branch $branch): bool
    {
        return $this->delete($user, $branch);
    }

    public function forceDelete(User $user, Branch $branch): bool
    {
        return $user->hasRole('super_admin');
    }
}
