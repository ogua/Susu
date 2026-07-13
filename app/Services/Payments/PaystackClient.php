<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over Paystack's charge/verify/initialize endpoints (AD-13).
 * No SDK package — just the Http facade, so removing/swapping providers
 * later never touches vendor code. All amounts are already minor units
 * (pesewas), matching Paystack's own convention.
 */
class PaystackClient
{
    private string $secretKey;

    private string $baseUrl;

    public function __construct(?string $secretKey = null, ?string $baseUrl = null)
    {
        $this->secretKey = $secretKey ?? (string) config('services.paystack.secret_key');
        $this->baseUrl = $baseUrl ?? (string) config('services.paystack.base_url');
    }

    /**
     * Initiates a mobile money charge — this is what triggers the PIN prompt
     * on the customer's phone. Response `data.status` is one of
     * pay_offline|send_otp|success|failed (PaymentIntentStatus mirrors these).
     *
     * @return array<string, mixed>
     */
    public function chargeMobileMoney(string $email, int $amountMinorUnits, string $reference, string $phone, string $provider): array
    {
        return $this->request()->post('/charge', [
            'email' => $email,
            'amount' => (string) $amountMinorUnits,
            'reference' => $reference,
            'mobile_money' => [
                'phone' => $phone,
                'provider' => $provider,
            ],
        ])->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function submitOtp(string $reference, string $otp): array
    {
        return $this->request()->post('/charge/submit_otp', [
            'reference' => $reference,
            'otp' => $otp,
        ])->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $reference): array
    {
        return $this->request()->get("/transaction/verify/{$reference}")->json();
    }

    /**
     * Hosted checkout fallback — returns an authorization_url to open in-app
     * (expo-web-browser) or a new tab on web.
     *
     * @return array<string, mixed>
     */
    public function initializeTransaction(string $email, int $amountMinorUnits, string $reference, string $callbackUrl): array
    {
        return $this->request()->post('/transaction/initialize', [
            'email' => $email,
            'amount' => (string) $amountMinorUnits,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
        ])->json();
    }

    /** Paystack signs the raw webhook body with HMAC-SHA512 using the secret key. */
    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        if ($signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $rawBody, $this->secretKey);

        return hash_equals($expected, $signatureHeader);
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->secretKey)
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout(20);
    }
}
