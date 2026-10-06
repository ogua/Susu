<?php

namespace App\Actions\License;

use App\Actions\Billing\InvoiceCheckoutAction;
use App\Models\DesktopLicenseSale;

/**
 * Applies a Paystack webhook event for a license sale. The route's
 * VerifyPaystackSignature middleware has already authenticated the payload,
 * so no signature check happens here — only idempotent application via
 * FulfillLicenseSaleAction::complete(), the same path the checkout callback
 * uses, so whichever confirms first wins and the other is a no-op. Kept
 * entirely separate from HandlePaystackWebhookAction (the susu-payment
 * webhook) so this never touches that money-movement code path.
 */
class HandleLicenseWebhookAction
{
    public function __construct(
        private FulfillLicenseSaleAction $fulfill,
        private InvoiceCheckoutAction $invoiceCheckout,
    ) {}

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

        // Subscription invoices are the other platform-billing payment and
        // share this endpoint (their SUSULIC-SUB- prefix routes them here).
        if (InvoiceCheckoutAction::isInvoiceReference($reference)) {
            $this->invoiceCheckout->complete($reference, $data['status'] ?? $this->statusFromEvent($payload['event'] ?? ''), $payload);

            return;
        }

        $sale = DesktopLicenseSale::where('provider_reference', $reference)->first();
        if ($sale === null) {
            return;
        }

        $rawStatus = $data['status'] ?? $this->statusFromEvent($payload['event'] ?? '');
        $this->fulfill->complete($sale, $rawStatus, $payload);
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
