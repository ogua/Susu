<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Branch;
use Illuminate\Http\Request;

trait ResolvesRequestBranch
{
    /**
     * Company admins may look at any of their branches via ?branch_id and,
     * having no home branch of their own, default to their company's first
     * branch. Everyone else is pinned to their own branch.
     */
    protected function resolveBranch(Request $request): Branch
    {
        $user = $request->user();

        $validated = $request->validate([
            'branch_id' => ['nullable', 'uuid'],
        ]);

        if (! $user->hasRole('company_admin')) {
            return Branch::findOrFail($user->branch_id);
        }

        $companyBranches = Branch::query()->where('company_id', $user->company_id);

        if (isset($validated['branch_id'])) {
            return $companyBranches->findOrFail($validated['branch_id']);
        }

        if ($user->branch_id) {
            return (clone $companyBranches)->find($user->branch_id) ?? $companyBranches->oldest()->firstOrFail();
        }

        return $companyBranches->oldest()->firstOrFail();
    }
}
