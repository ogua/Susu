<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Agents\BuildAgentPositionsAction;
use App\Http\Controllers\Api\V1\Concerns\ResolvesRequestBranch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live positions of a branch's agents for managers — the same list as the
 * web Agent Tracking map. Amounts are integer minor units.
 */
class AgentPositionController extends Controller
{
    use ResolvesRequestBranch;

    public function index(Request $request, BuildAgentPositionsAction $buildPositions): JsonResponse
    {
        $branch = $this->resolveBranch($request);

        return response()->json([
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'stale_after_minutes' => BuildAgentPositionsAction::STALE_MINUTES,
            'data' => $buildPositions->execute($branch),
        ]);
    }
}
