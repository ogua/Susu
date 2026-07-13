<?php

namespace App\Http\Middleware;

use App\Services\Payments\PaystackClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any /webhooks/paystack request whose X-Paystack-Signature doesn't
 * match an HMAC-SHA512 of the raw body under our secret key — the only line
 * of defense on a public, unauthenticated endpoint.
 */
class VerifyPaystackSignature
{
    public function __construct(private PaystackClient $paystack) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $valid = $this->paystack->verifyWebhookSignature(
            $request->getContent(),
            $request->header('X-Paystack-Signature'),
        );

        abort_unless($valid, 401, 'Invalid webhook signature.');

        return $next($request);
    }
}
