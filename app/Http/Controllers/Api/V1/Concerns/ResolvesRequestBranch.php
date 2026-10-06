<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Branch;
use App\Support\StaffBranch;
use Illuminate\Http\Request;

trait ResolvesRequestBranch
{
    /**
     * The branch named by ?branch_id (when the user may access it), else the
     * user's working branch — see StaffBranch.
     */
    protected function resolveBranch(Request $request): Branch
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'uuid'],
        ]);

        return StaffBranch::resolve($request->user(), $validated['branch_id'] ?? null);
    }
}
