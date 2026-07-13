<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Actions\Payments\HandlePaystackWebhookAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, HandlePaystackWebhookAction $action): JsonResponse
    {
        $action->execute($request->json()->all());

        return response()->json(['received' => true]);
    }
}
