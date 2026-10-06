<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Authenticate by email or phone and issue a Sanctum token whose
     * ability is the user's primary role.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where($request->loginColumn(), $request->string('login')->value())->first();

        if (! $user || ! Hash::check($request->string('password')->value(), $user->password)) {
            throw ValidationException::withMessages([
                'login' => [__('auth.failed')],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'login' => ['This account has been deactivated.'],
            ]);
        }

        if (! $user->hasActiveAccess()) {
            throw ValidationException::withMessages([
                'login' => ['This company\'s account has been suspended. Please contact your administrator.'],
            ]);
        }

        $role = $user->getRoleNames()->first() ?? 'none';

        $token = $user->createToken(
            $request->string('device_name')->value(),
            ['role:'.$role],
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => UserResource::make($user->load(['company', 'branches'])),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => UserResource::make($request->user()->load(['company', 'branches'])),
        ]);
    }
}
