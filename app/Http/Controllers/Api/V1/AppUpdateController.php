<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AppUpdatePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public on purpose (no token): a signed-out device, or one whose company is
 * suspended, must still be told it has to update. Never errors — an unknown
 * platform or version just gets a policy that prompts nothing.
 */
class AppUpdateController extends Controller
{
    public function __invoke(Request $request, AppUpdatePolicy $policy): JsonResource
    {
        return JsonResource::make($policy->resolve(
            $request->string('platform')->toString() ?: null,
            $request->string('version')->toString() ?: null,
        ));
    }
}
