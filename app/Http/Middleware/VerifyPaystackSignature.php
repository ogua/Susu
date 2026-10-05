<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\Payments\PaystackClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any /webhooks/paystack request whose X-Paystack-Signature doesn't
 * match an HMAC-SHA512 of the raw body under our secret key — the only line
 * of defense on a public, unauthenticated endpoint.
 *
 * On the per-company route ({company}) the key is that company's own
 * Paystack secret, and the resolved company is handed on as the
 * `paystack_company` request attribute so the webhook only touches its
 * intents.
 */
class VerifyPaystackSignature
{
    public function __construct(private PaystackClient $paystack) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $paystack = $this->paystack;

        if ($request->route('company') !== null) {
            $company = Company::with('paymentSetting')->find($request->route('company'));

            abort_unless($company !== null && PaystackClient::usesCompanyAccount($company), 404);

            $paystack = $paystack->forCompany($company);
            $request->attributes->set('paystack_company', $company);
        }

        $valid = $paystack->verifyWebhookSignature(
            $request->getContent(),
            $request->header('X-Paystack-Signature'),
        );

        abort_unless($valid, 401, 'Invalid webhook signature.');

        return $next($request);
    }
}
