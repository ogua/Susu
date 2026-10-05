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
 * Hosted-checkout fallback (AD-13's "flexible" second flow): returns an
 * authorization_url the client opens in-app (expo-web-browser) or a new tab
 * on web. The customer completes payment on Paystack's own page; the same
 * webhook/verify race then confirms it, exactly like the Charge API flow.
 */
class InitializeCheckoutAction
{
    use ResolvesCustomerEmail;

    public function __construct(private PaystackClient $paystack) {}

    /**
     * @return array{intent: PaymentIntent, authorization_url: ?string}
     */
    public function execute(
        User $initiatedBy,
        SavingsAccount $account,
        int $amount,
        string $callbackUrl,
        ?string $clientReference = null,
    ): array {
        $clientReference ??= (string) Str::uuid();

        $existing = PaymentIntent::where('client_reference', $clientReference)->first();
        if ($existing !== null) {
            $authorizationUrl = $existing->raw_response['data']['authorization_url'] ?? null;

            return ['intent' => $existing, 'authorization_url' => $authorizationUrl];
        }

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
            'flow' => PaymentFlow::Checkout,
            'paystack_account' => PaystackClient::usesCompanyAccount($account->company) ? 'company' : 'platform',
            'amount' => $amount,
            'status' => PaymentIntentStatus::Initiated,
            'client_reference' => $clientReference,
        ]);

        // SUSU- routes this webhook through oguapaymentwebhook, the one URL
        // shared by every Ogua project. Applied only to what Paystack sees —
        // client_reference (the caller's own idempotency key) stays as given.
        $providerReference = 'SUSU-'.$clientReference;

        $response = $paystack->initializeTransaction(
            $this->emailFor($account, $initiatedBy),
            $amount,
            $providerReference,
            $callbackUrl,
        );
        $data = $response['data'] ?? [];

        $intent->forceFill([
            'status' => $data['authorization_url'] ?? null ? PaymentIntentStatus::Pending : PaymentIntentStatus::Failed,
            // Always $providerReference, not $data['reference'] — we told
            // Paystack exactly what reference to use, so trust that over
            // whatever comes back (Paystack echoes it verbatim in practice,
            // but falling back to a returned value here would silently drop
            // the SUSU- prefix the gateway depends on if it ever didn't).
            'provider_reference' => $providerReference,
            'raw_response' => $response,
        ])->save();

        return ['intent' => $intent->fresh(), 'authorization_url' => $data['authorization_url'] ?? null];
    }
}
