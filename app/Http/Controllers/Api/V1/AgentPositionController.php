<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Agents\BuildAgentPositionsAction;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live positions of a branch's agents for managers — the same list as the
 * web Agent Tracking map. Amounts are integer minor units.
 */
class AgentPositionController extends Controller
{
    public function index(Request $request, BuildAgentPositionsAction $buildPositions): JsonResponse
    {
        $branch = $this->resolveBranch($request);

        return response()->json([
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'stale_after_minutes' => BuildAgentPositionsAction::STALE_MINUTES,
            'data' => $buildPositions->execute($branch),
        ]);
    }

    /**
     * Company admins may look at any of their branches via ?branch_id;
     * branch managers are pinned to their own branch.
     */
    private function resolveBranch(Request $request): Branch
    {
        $user = $request->user();

        $validated = $request->validate([
            'branch_id' => ['nullable', 'uuid'],
        ]);

        if (isset($validated['branch_id']) && $user->hasRole('company_admin')) {
            return Branch::query()
                ->where('company_id', $user->company_id)
                ->findOrFail($validated['branch_id']);
        }

        return Branch::findOrFail($user->branch_id);
    }
}
