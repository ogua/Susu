<?php

namespace App\Actions\Company;

use App\Models\Branch;
use App\Models\User;

/**
 * Filament tenancy only lets a user into branches they hold a branch_users
 * row for, while accessibleBranchIds() (API/sync) treats a company_admin as
 * reaching every branch of their company. This keeps the two in step: a
 * company admin is granted every branch of their company, and their primary
 * branch is set when missing so the API's home-branch fallbacks resolve.
 */
class SyncCompanyAdminBranchAccessAction
{
    public function execute(User $user): void
    {
        if ($user->company_id === null || ! $user->hasRole('company_admin')) {
            return;
        }

        $branchIds = Branch::where('company_id', $user->company_id)->orderBy('created_at')->pluck('id');

        if ($branchIds->isEmpty()) {
            return;
        }

        $user->branches()->syncWithoutDetaching($branchIds);

        if ($user->branch_id === null || ! $branchIds->contains($user->branch_id)) {
            $user->update(['branch_id' => $branchIds->first()]);
        }
    }
}
