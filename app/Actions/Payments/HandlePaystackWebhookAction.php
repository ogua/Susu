<?php

namespace App\Actions\Payments;

use App\Enums\PaymentIntentStatus;
use App\Models\PaymentIntent;

/**
 * Applies a Paystack webhook event. The route's VerifyPaystackSignature
 * middleware has already authenticated the payload before this runs, so no
 * signature check happens here — only idempotent application via the same
 * complete() path the client's verify screen uses (VerifyPaymentIntentAction),
 * so whichever of the two confirms first wins and the other is a no-op.
 */
class HandlePaystackWebhookAction
{
    public function __construct(private VerifyPaymentIntentAction $verify) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(array $payload): void
    {
        $data = $payload['data'] ?? [];
        $reference = $data['reference'] ?? null;
        if ($reference === null) {
            return;
        }

        $intent = PaymentIntent::where('provider_reference', $reference)
            ->orWhere('client_reference', $reference)
            ->first();

        if ($intent === null) {
            return;
        }

        $rawStatus = $data['status'] ?? $this->statusFromEvent($payload['event'] ?? '');
        $this->verify->complete($intent, PaymentIntentStatus::fromProviderStatus($rawStatus), $payload);
    }

    private function statusFromEvent(string $event): string
    {
        return match ($event) {
            'charge.success' => 'success',
            'charge.failed' => 'failed',
            default => 'pending',
        };
    }
}
