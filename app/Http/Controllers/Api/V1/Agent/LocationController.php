<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Actions\Agents\RecordLocationPingsAction;
use App\Actions\Agents\SetDutyStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLocationPingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function store(StoreLocationPingsRequest $request, RecordLocationPingsAction $action): JsonResponse
    {
        $stored = $action->execute($request->user(), $request->validated('pings'));

        return response()->json(['stored' => $stored], 201);
    }

    public function duty(Request $request, SetDutyStatusAction $action): JsonResponse
    {
        $validated = $request->validate(['on_duty' => ['required', 'boolean']]);

        $onDuty = (bool) $validated['on_duty'];
        $position = $action->execute($request->user(), $onDuty);

        return response()->json(['on_duty' => $position->on_duty ?? $onDuty]);
    }
}
