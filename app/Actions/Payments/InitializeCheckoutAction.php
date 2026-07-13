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

        $intent = PaymentIntent::create([
            'company_id' => $account->company_id,
            'branch_id' => $account->branch_id,
            'payable_type' => SavingsAccount::class,
            'payable_id' => $account->id,
            'initiated_by' => $initiatedBy->id,
            'flow' => PaymentFlow::Checkout,
            'amount' => $amount,
            'status' => PaymentIntentStatus::Initiated,
            'client_reference' => $clientReference,
        ]);

        $response = $this->paystack->initializeTransaction(
            $this->emailFor($account, $initiatedBy),
            $amount,
            $clientReference,
            $callbackUrl,
        );
        $data = $response['data'] ?? [];

        $intent->forceFill([
            'status' => $data['authorization_url'] ?? null ? PaymentIntentStatus::Pending : PaymentIntentStatus::Failed,
            'provider_reference' => $data['reference'] ?? $clientReference,
            'raw_response' => $response,
        ])->save();

        return ['intent' => $intent->fresh(), 'authorization_url' => $data['authorization_url'] ?? null];
    }
}
