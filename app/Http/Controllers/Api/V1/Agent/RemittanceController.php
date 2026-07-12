<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Actions\Agents\RecordAgentRemittanceAction;
use App\Enums\ClientOrigin;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\JournalEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RemittanceController extends Controller
{
    public function store(Request $request, RecordAgentRemittanceAction $action): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'client_reference' => ['nullable', 'uuid'],
        ]);

        $entry = $action->execute(
            agent: $request->user(),
            amount: (int) $validated['amount'],
            clientReference: $validated['client_reference'] ?? null,
            origin: ClientOrigin::Mobile,
        );

        return JournalEntryResource::make($entry)->response()->setStatusCode(201);
    }
}
