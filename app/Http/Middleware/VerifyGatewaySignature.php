<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confirms a webhook actually came from oguapaymentwebhook, the shared
 * gateway that holds the one URL Paystack allows for all Ogua projects.
 *
 * The gateway relays the real X-Paystack-Signature header unchanged, so
 * that header alone isn't enough to tell a genuine forward apart from
 * someone replaying a signature they observed on a *different* project —
 * every project on this account shares the same Paystack secret key. This
 * checks a second, gateway-specific signature that only the gateway knows.
 */
class VerifyGatewaySignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Gateway-Signature');
        $secret = (string) config('services.webhook_gateway.forward_secret');

        if (blank($signature) || $secret === '') {
            abort(401);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(401);
        }

        return $next($request);
    }
}
