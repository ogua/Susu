<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API counterpart of RequirePasswordChange: while a user is on a temporary
 * password only the auth endpoints answer; everything else returns 403 with
 * code "password_change_required" so the apps can open their change screen.
 */
class RequireApiPasswordChange
{
    /** Routes a user on a temporary password may still call. */
    private const ALLOWED_ROUTES = [
        'api.v1.auth.me',
        'api.v1.auth.logout',
        'api.v1.auth.password.update',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            return response()->json([
                'message' => 'You must change your temporary password before continuing.',
                'code' => 'password_change_required',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
