<?php

namespace App\Policies;

use App\Models\User;

/**
 * Governs the branch-level Staff resource (App\Filament\Resources\Staff),
 * where company_admin/branch_manager manage their own company's staff.
 * Hierarchical by design: a role may only manage roles below it, and
 * branch_manager is further scoped to the branches they belong to. Nobody
 * manages themselves here (see canManage()) — that's the profile page's job.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function view(User $user, User $target): bool
    {
        return $this->canManage($user, $target);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function update(User $user, User $target): bool
    {
        return $this->canManage($user, $target);
    }

    /**
     * Roles $user may assign to staff they manage, most-senior first.
     *
     * @return list<string>
     */
    public function assignableRoles(User $user): array
    {
        if ($user->hasRole('company_admin')) {
            return ['company_admin', 'branch_manager', 'field_agent'];
        }

        if ($user->hasRole('branch_manager')) {
            return ['field_agent'];
        }

        return [];
    }

    private function canManage(User $user, User $target): bool
    {
        if ($user->is($target)) {
            return false;
        }

        if ($user->company_id !== $target->company_id) {
            return false;
        }

        if ($user->hasRole('company_admin')) {
            return $target->hasAnyRole(['company_admin', 'branch_manager', 'field_agent']);
        }

        if ($user->hasRole('branch_manager')) {
            return $target->hasRole('field_agent')
                && $target->branches()->whereIn('branches.id', $user->branches()->pluck('branches.id'))->exists();
        }

        return false;
    }
}
