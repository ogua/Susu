<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Agents\BuildAgentRouteAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShowAgentRouteRequest;
use App\Models\AgentLivePosition;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * One field agent's movement for a day (route, stops, geotagged
 * collections) for managers — the same data as the web "Track agent" page.
 * Amounts are integer minor units.
 */
class AgentRouteController extends Controller
{
    public function show(ShowAgentRouteRequest $request, User $agent, BuildAgentRouteAction $buildRoute): JsonResponse
    {
        $position = AgentLivePosition::query()->where('agent_id', $agent->id)->first();

        return response()->json([
            'data' => [
                'agent' => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'phone' => $agent->phone,
                    'photo_url' => $agent->photo_url,
                    'on_duty' => (bool) $position?->on_duty,
                    'last_seen_at' => $position?->located_at?->toIso8601String(),
                ],
                ...$buildRoute->execute($agent, $request->routeDate()),
            ],
        ]);
    }
}
