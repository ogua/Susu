<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Staff\UpdateProfilePhotoAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProfilePhotoRequest;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets the signed-in user set or remove their own profile photo.
 */
class ProfilePhotoController extends Controller
{
    public function update(UpdateProfilePhotoRequest $request, UpdateProfilePhotoAction $updatePhoto): JsonResponse
    {
        $user = $updatePhoto->execute($request->user(), $request->file('photo'));

        return response()->json([
            'user' => UserResource::make($user->load(['company', 'branches'])),
        ]);
    }

    public function destroy(Request $request, UpdateProfilePhotoAction $updatePhoto): JsonResponse
    {
        $user = $updatePhoto->execute($request->user(), null);

        return response()->json([
            'user' => UserResource::make($user->load(['company', 'branches'])),
        ]);
    }
}
