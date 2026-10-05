<?php

namespace App\Actions\Payments;

use App\Enums\PaymentIntentStatus;
use App\Models\PaymentIntent;
use App\Services\Payments\PaystackClient;
use Illuminate\Validation\ValidationException;

/** Submits the OTP some voucher networks (e.g. Telecel) require mid-charge. */
class SubmitChargeOtpAction
{
    public function __construct(
        private PaystackClient $paystack,
        private VerifyPaymentIntentAction $verify,
    ) {}

    public function execute(PaymentIntent $intent, string $otp): PaymentIntent
    {
        if ($intent->status !== PaymentIntentStatus::SendOtp) {
            throw ValidationException::withMessages(['otp' => 'This payment is not waiting for an OTP.']);
        }

        $response = $this->paystack->forIntent($intent)->submitOtp($intent->provider_reference ?? $intent->client_reference, $otp);
        $data = $response['data'] ?? [];

        // Routed through complete() so an OTP that immediately confirms the
        // charge posts the ledger entry, exactly like the other paths.
        return $this->verify->complete(
            $intent,
            PaymentIntentStatus::fromProviderStatus($data['status'] ?? 'pending'),
            $response,
        );
    }
}
