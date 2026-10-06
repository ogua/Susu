<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Staff\ChangeOwnPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * Lets the signed-in user replace their password — required before any other
 * endpoint answers while users.must_change_password is set.
 */
class PasswordController extends Controller
{
    public function update(ChangePasswordRequest $request, ChangeOwnPasswordAction $changePassword): JsonResponse
    {
        $user = $changePassword->execute(
            $request->user(),
            $request->string('current_password')->value(),
            $request->string('password')->value(),
        );

        return response()->json([
            'user' => UserResource::make($user->load(['company', 'branches'])),
        ]);
    }
}
