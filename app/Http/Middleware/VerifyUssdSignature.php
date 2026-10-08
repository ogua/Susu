<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confirms a request really came from the central Ogua USSD platform.
 *
 * The platform signs every call with a secret shared only with this app:
 * X-Ussd-Signature = HMAC-SHA256("{timestamp}.{nonce}.{raw body}", secret).
 * Requests outside a five-minute window are refused, and each nonce is
 * accepted once, so a captured request cannot be replayed.
 */
class VerifyUssdSignature
{
    private const WINDOW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.ussd.secret');
        $client = (string) $request->header('X-Ussd-Client');
        $timestamp = (string) $request->header('X-Ussd-Timestamp');
        $nonce = (string) $request->header('X-Ussd-Nonce');
        $signature = (string) $request->header('X-Ussd-Signature');

        if ($secret === '' || $client !== config('services.ussd.client_id') || $nonce === '' || $signature === '') {
            return $this->errorResponse('Missing or unconfigured USSD signature.');
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::WINDOW_SECONDS) {
            return $this->errorResponse('USSD request timestamp expired.');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$nonce}.{$request->getContent()}", $secret);

        if (! hash_equals($expected, $signature)) {
            return $this->errorResponse('Invalid USSD signature.');
        }

        if (! Cache::add('ussd-nonce:'.hash('sha256', $nonce), true, self::WINDOW_SECONDS * 2)) {
            return $this->errorResponse('USSD request already processed.');
        }

        return $next($request);
    }

    private function errorResponse(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 401);
    }
}
