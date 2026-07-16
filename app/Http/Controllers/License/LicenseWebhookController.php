<?php

namespace App\Http\Controllers\License;

use App\Actions\License\HandleLicenseWebhookAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicenseWebhookController extends Controller
{
    public function __invoke(Request $request, HandleLicenseWebhookAction $action): JsonResponse
    {
        $action->execute($request->json()->all());

        return response()->json(['received' => true]);
    }
}
