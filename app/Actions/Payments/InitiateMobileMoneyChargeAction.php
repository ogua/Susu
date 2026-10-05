<?php

namespace App\Actions\Payments;

use App\Actions\Payments\Concerns\ResolvesCustomerEmail;
use App\Enums\PaymentFlow;
use App\Enums\PaymentIntentStatus;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Payments\PaystackClient;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Initiates a Paystack mobile money charge (Charge API flow) — the PIN
 * prompt appears on the customer's phone right after this call returns, so
 * the caller should navigate straight to the verify screen. Idempotent on
 * client_reference so a retried tap can never double-charge.
 */
class InitiateMobileMoneyChargeAction
{
    use ResolvesCustomerEmail;

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

        $this->verify->assertCreditable($initiatedBy, $account, $amount);

        $account->loadMissing('company.paymentSetting');
        $paystack = $this->paystack->forCompany($account->company);

        if (! $paystack->isConfigured()) {
            throw ValidationException::withMessages([
                'amount' => 'Mobile money payments are not set up for this company yet.',
            ]);
        }

        $intent = PaymentIntent::create([
            'company_id' => $account->company_id,
            'branch_id' => $account->branch_id,
            'payable_type' => SavingsAccount::class,
            'payable_id' => $account->id,
            'initiated_by' => $initiatedBy->id,
            'flow' => PaymentFlow::ChargeApi,
            'paystack_account' => PaystackClient::usesCompanyAccount($account->company) ? 'company' : 'platform',
            'channel' => $provider,
            'phone' => $phone,
            'amount' => $amount,
            'status' => PaymentIntentStatus::Initiated,
            'client_reference' => $clientReference,
        ]);

        // SUSU- routes this webhook through oguapaymentwebhook, the one URL
        // shared by every Ogua project. Applied only to what Paystack sees —
        // client_reference (the caller's own idempotency key) stays as given.
        $providerReference = 'SUSU-'.$clientReference;

        $response = $paystack->chargeMobileMoney(
            email: $this->emailFor($account, $initiatedBy),
            amountMinorUnits: $amount,
            reference: $providerReference,
            phone: $phone,
            provider: $provider,
        );

        $data = $response['data'] ?? [];

        // Always $providerReference, not $data['reference'] — we told
        // Paystack exactly what reference to use, so trust that over
        // whatever comes back (Paystack echoes it verbatim in practice, but
        // falling back to a returned value here would silently drop the
        // SUSU- prefix the gateway depends on if it ever didn't).
        $intent->forceFill(['provider_reference' => $providerReference])->save();

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
}
