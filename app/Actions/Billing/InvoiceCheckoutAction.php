<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Payments\PaystackClient;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Paystack hosted checkout for a subscription invoice, on the platform's
 * own Paystack account (never a company's — this is the company paying the
 * platform). Confirmation arrives by the checkout callback or the webhook;
 * both go through complete(), so whichever lands first settles the invoice
 * and the other is a no-op.
 */
class InvoiceCheckoutAction
{
    public function __construct(
        private readonly PaystackClient $paystack,
        private readonly RecordInvoicePaymentAction $recordPayment,
    ) {}

    public static function isInvoiceReference(?string $reference): bool
    {
        return $reference !== null && str_starts_with($reference, (string) config('billing.reference_prefix'));
    }

    /**
     * @return string The Paystack authorization URL to send the payer to.
     */
    public function start(SubscriptionInvoice $invoice, User $payer, string $callbackUrl): string
    {
        if ($invoice->status !== InvoiceStatus::Unpaid) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is not awaiting payment.']);
        }

        $reference = config('billing.reference_prefix').Str::uuid();
        $response = $this->paystack->initializeTransaction($payer->email, $invoice->amount, $reference, $callbackUrl);
        $authorizationUrl = $response['data']['authorization_url'] ?? null;

        if ($authorizationUrl === null) {
            throw ValidationException::withMessages(['invoice' => 'Could not start the payment. Please try again.']);
        }

        $invoice->update(['provider_reference' => $reference, 'raw_response' => $response]);

        return $authorizationUrl;
    }

    /**
     * Applies a Paystack result for an invoice reference; returns null when
     * the reference isn't one of ours.
     *
     * @param  array<string, mixed>  $rawResponse
     */
    public function complete(string $reference, string $paystackStatus, array $rawResponse): ?SubscriptionInvoice
    {
        $invoice = SubscriptionInvoice::where('provider_reference', $reference)->first();
        if ($invoice === null) {
            return null;
        }

        if ($paystackStatus !== 'success') {
            return $invoice;
        }

        $paidAmount = (int) ($rawResponse['data']['amount'] ?? $rawResponse['amount'] ?? 0);
        if ($paidAmount !== 0 && $paidAmount < $invoice->amount) {
            report(new \RuntimeException("Paystack paid {$paidAmount} for invoice {$invoice->number} of {$invoice->amount}."));

            return $invoice;
        }

        return $this->recordPayment->execute($invoice, 'paystack', $reference, rawResponse: $rawResponse);
    }

    /** The checkout callback: asks Paystack for the outcome rather than trusting the redirect. */
    public function verifyAndComplete(string $reference): ?SubscriptionInvoice
    {
        $response = $this->paystack->verify($reference);

        return $this->complete($reference, (string) ($response['data']['status'] ?? 'failed'), $response);
    }
}
