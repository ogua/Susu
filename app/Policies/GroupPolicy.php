<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function view(User $user, Group $group): bool
    {
        return $user->company_id === $group->company_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function update(User $user, Group $group): bool
    {
        return $user->company_id === $group->company_id && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function activate(User $user, Group $group): bool
    {
        return $user->company_id === $group->company_id && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function recordContribution(User $user, Group $group): bool
    {
        return $user->company_id === $group->company_id
            && $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function payout(User $user, Group $group): bool
    {
        return $user->company_id === $group->company_id && $user->hasRole(['company_admin', 'branch_manager']);
    }
}
