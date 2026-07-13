<?php

namespace App\Actions\Payments;

use App\Enums\PaymentFlow;
use App\Enums\PaymentIntentStatus;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Payments\PaystackClient;
use Illuminate\Support\Str;

/**
 * Initiates a Paystack mobile money charge (Charge API flow) — the PIN
 * prompt appears on the customer's phone right after this call returns, so
 * the caller should navigate straight to the verify screen. Idempotent on
 * client_reference so a retried tap can never double-charge.
 */
class InitiateMobileMoneyChargeAction
{
    public function __construct(
        private PaystackClient $paystack,
        private VerifyPaymentIntentAction $verify,
    ) {}

    public function execute(
        User $initiatedBy,
        SavingsAccount $account,
        int $amount,
        string $phone,
        string $provider,
        ?string $clientReference = null,
    ): PaymentIntent {
        $clientReference ??= (string) Str::uuid();

        $existing = PaymentIntent::where('client_reference', $clientReference)->first();
        if ($existing !== null) {
            return $existing;
        }

        $intent = PaymentIntent::create([
            'company_id' => $account->company_id,
            'branch_id' => $account->branch_id,
            'payable_type' => SavingsAccount::class,
            'payable_id' => $account->id,
            'initiated_by' => $initiatedBy->id,
            'flow' => PaymentFlow::ChargeApi,
            'channel' => $provider,
            'phone' => $phone,
            'amount' => $amount,
            'status' => PaymentIntentStatus::Initiated,
            'client_reference' => $clientReference,
        ]);

        $response = $this->paystack->chargeMobileMoney(
            email: $this->emailFor($account, $initiatedBy),
            amountMinorUnits: $amount,
            reference: $clientReference,
            phone: $phone,
            provider: $provider,
        );

        $data = $response['data'] ?? [];
        $intent->forceFill(['provider_reference' => $data['reference'] ?? $clientReference])->save();

        // Routed through the same complete() the webhook/verify race uses:
        // Paystack occasionally returns an immediate 'success' on the charge
        // call itself (no pay_offline/send_otp step), and that must post the
        // ledger entry exactly like any other confirmation path would.
        return $this->verify->complete(
            $intent->fresh(),
            PaymentIntentStatus::fromProviderStatus($data['status'] ?? 'pending'),
            $response,
        );
    }

    /** Paystack requires an email; susu customers rarely have one, so a stable synthetic one is used. */
    private function emailFor(SavingsAccount $account, User $initiatedBy): string
    {
        $account->loadMissing('customer');
        $phone = $account->customer?->phone ?? $initiatedBy->phone ?? $account->id;
        $slug = preg_replace('/[^a-z0-9]+/i', '', $phone);

        return "{$slug}@customers.susuapp.invalid";
    }
}
