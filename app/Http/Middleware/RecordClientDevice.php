<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers which app and version each signed-in device runs, from the
 * X-Client-Platform / X-App-Version headers the mobile and desktop apps send
 * (X-Client-Origin as a fallback for older desktop builds). Written only
 * when it changes, so it costs nothing per request. Feeds the super admin's
 * Devices page; older apps without the headers simply show "unknown".
 */
class RecordClientDevice
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $platform = $this->clean($request->header('X-Client-Platform') ?? $request->header('X-Client-Origin'), 20);
            $version = $this->clean($request->header('X-App-Version'), 30);

            $changes = array_filter([
                'client_platform' => $platform !== null && $platform !== $token->client_platform ? $platform : null,
                'client_version' => $version !== null && $version !== $token->client_version ? $version : null,
            ]);

            if ($changes !== []) {
                $token->forceFill($changes)->save();
            }
        }

        return $next($request);
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr(strtolower($value), 0, $max);
    }
}
