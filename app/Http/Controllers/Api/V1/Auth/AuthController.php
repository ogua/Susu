<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\Auth\ApiTwoFactorChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly ApiTwoFactorChallenge $twoFactor) {}

    /**
     * Authenticate by email or phone. Users who need a second factor (see
     * ApiTwoFactorChallenge) get {two_factor_required, challenge_token, …}
     * and finish at POST /auth/login/two-factor; everyone else gets a Sanctum
     * token whose ability is their primary role. `silent: true` (the desktop's
     * background re-sign-in) starts the challenge without sending a code.
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

        if ($this->twoFactor->requiredFor($user)) {
            return response()->json([
                'two_factor_required' => true,
                ...$this->twoFactor->start($user, sendCode: ! $request->boolean('silent')),
            ]);
        }

        return $this->issueToken($user, $request->string('device_name')->value());
    }

    /** Second step: the challenge token from login plus the code. */
    public function twoFactor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'string', 'max:64'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = $this->twoFactor->verify($validated['challenge_token'], $validated['code']);

        if (! $user->hasActiveAccess()) {
            throw ValidationException::withMessages(['code' => ['This account can no longer sign in.']]);
        }

        return $this->issueToken($user, $validated['device_name']);
    }

    /** Sends a new email/SMS code for a pending challenge. */
    public function resendTwoFactor(Request $request): JsonResponse
    {
        $validated = $request->validate(['challenge_token' => ['required', 'string']]);

        return response()->json([
            'code_sent_to' => $this->twoFactor->resend($validated['challenge_token']),
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

    private function issueToken(User $user, string $deviceName): JsonResponse
    {
        $role = $user->getRoleNames()->first() ?? 'none';

        $token = $user->createToken($deviceName, ['role:'.$role]);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => UserResource::make($user->load(['company', 'branches'])),
        ]);
    }
}
