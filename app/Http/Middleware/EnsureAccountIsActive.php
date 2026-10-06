<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum tokens outlive the checks made at login, so a user deactivated by
 * their company — or a whole company suspended by the platform — would keep
 * working on an existing token. This revokes that token and answers 401,
 * which the mobile and desktop clients already treat as "session ended".
 */
class EnsureAccountIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->hasActiveAccess()) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => $user->is_active
                    ? 'This company\'s account has been suspended.'
                    : 'This account has been deactivated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
