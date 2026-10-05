<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Staff API lookups are limited to the branches the user may act on
 * (User::accessibleBranchIds — the same rule as Filament tenancy, with
 * company admins seeing the whole company). Customers are already scoped
 * by ownership, so they pass through untouched.
 */
trait ScopesToAccessibleBranches
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scopeToBranches(Builder $query, User $user, string $column = 'branch_id'): Builder
    {
        return $user->hasRole('customer')
            ? $query
            : $query->whereIn($query->qualifyColumn($column), $user->accessibleBranchIds());
    }

    protected function canSeeBranch(User $user, ?string $branchId): bool
    {
        return $user->hasRole('customer') || $user->canAccessBranchId($branchId);
    }
}
