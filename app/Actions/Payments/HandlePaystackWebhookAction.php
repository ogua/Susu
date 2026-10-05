<?php

namespace App\Actions\Payments;

use App\Enums\PaymentIntentStatus;
use App\Models\Company;
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
     * A company webhook ($company set) may only settle that company's own
     * company-account intents, and a platform webhook only platform-account
     * ones — so a company's key can never confirm a charge it didn't take.
     *
     * @param  array<string, mixed>  $payload
     */
    public function execute(array $payload, ?Company $company = null): void
    {
        $data = $payload['data'] ?? [];
        $reference = $data['reference'] ?? null;
        if ($reference === null) {
            return;
        }

        $intent = PaymentIntent::query()
            ->where(fn ($query) => $query->where('provider_reference', $reference)->orWhere('client_reference', $reference))
            ->when(
                $company !== null,
                fn ($query) => $query->where('company_id', $company->id)->where('paystack_account', 'company'),
                fn ($query) => $query->where('paystack_account', 'platform'),
            )
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
