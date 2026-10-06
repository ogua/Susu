<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The branch a staff member is working in for an API call or synced op.
 *
 * Any staff member may name a branch they can access (User::accessibleBranchIds —
 * the whole company for a company admin). Without one they work in their home
 * branch; a company admin, who has none, falls back to the company's first
 * branch. A branch outside their reach is a 404, never a silent fallback.
 */
final class StaffBranch
{
    public static function resolve(User $user, ?string $branchId = null): Branch
    {
        $accessible = $user->accessibleBranchIds();
        $branches = Branch::query()->where('company_id', $user->company_id)->whereIn('id', $accessible);

        if ($branchId !== null) {
            return $branches->findOrFail($branchId);
        }

        if ($user->branch_id !== null && in_array($user->branch_id, $accessible, true)) {
            return (clone $branches)->findOrFail($user->branch_id);
        }

        return $branches->oldest()->first()
            ?? throw (new ModelNotFoundException)->setModel(Branch::class);
    }
}
