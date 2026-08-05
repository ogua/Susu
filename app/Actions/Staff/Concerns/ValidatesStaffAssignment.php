<?php

namespace App\Actions\Staff\Concerns;

use App\Models\Branch;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Server-side re-validation of the role/branch choices the Staff form
 * offers — the form only *shows* an actor their assignable roles and
 * manageable branches, it doesn't stop a crafted request from submitting
 * something else, so both Create/Update actions must check again here.
 */
trait ValidatesStaffAssignment
{
    private function assertAssignableRole(User $actor, UserPolicy $policy, string $role): void
    {
        if (! in_array($role, $policy->assignableRoles($actor), true)) {
            throw ValidationException::withMessages(['role' => 'You are not allowed to assign this role.']);
        }
    }

    /**
     * @param  array<int, string>  $branchIds
     * @return Collection<int, string>
     */
    private function assertManageableBranches(User $actor, Branch $tenant, array $branchIds): Collection
    {
        $requested = collect($branchIds)->unique()->values();

        if ($requested->isEmpty()) {
            throw ValidationException::withMessages(['branch_ids' => 'Select at least one branch.']);
        }

        $allowed = $actor->hasRole('company_admin')
            ? Branch::where('company_id', $tenant->company_id)->pluck('id')
            : $actor->branches()->pluck('branches.id');

        if ($requested->diff($allowed)->isNotEmpty()) {
            throw ValidationException::withMessages(['branch_ids' => 'You cannot grant access to a branch you do not manage.']);
        }

        return $requested;
    }
}
