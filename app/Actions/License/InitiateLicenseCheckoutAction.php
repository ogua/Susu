<?php

namespace App\Actions\License;

use App\Enums\LicenseSaleStatus;
use App\Models\DesktopLicenseSale;
use App\Services\Payments\PaystackClient;
use Illuminate\Support\Str;

/**
 * Guest hosted-checkout for a desktop license (mirrors
 * App\Actions\Payments\InitializeCheckoutAction, minus the User/SavingsAccount
 * context that flow requires — a license purchase has neither).
 */
class InitiateLicenseCheckoutAction
{
    public function __construct(private PaystackClient $paystack) {}

    /**
     * @return array{sale: DesktopLicenseSale, authorization_url: ?string}
     */
    public function execute(
        string $installId,
        string $customerName,
        string $customerEmail,
        ?string $customerPhone,
        string $callbackUrl,
    ): array {
        // SUSULIC- (not SUSU-, kept distinct so oguapaymentwebhook can route
        // license-sale webhooks to this project's separate license endpoint,
        // deliberately kept apart from the money-movement susu flow) routes
        // this webhook through oguapaymentwebhook, the one URL shared by
        // every Ogua project.
        $reference = 'SUSULIC-'.Str::uuid();

        $sale = DesktopLicenseSale::create([
            'install_id' => $installId,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'duration_days' => config('license.duration_days'),
            'amount' => config('license.price'),
            'currency' => config('license.currency'),
            'status' => LicenseSaleStatus::Pending,
            'provider_reference' => $reference,
            'source' => 'web_purchase',
        ]);

        $response = $this->paystack->initializeTransaction(
            $customerEmail,
            $sale->amount,
            $reference,
            $callbackUrl,
        );
        $data = $response['data'] ?? [];
        $authorizationUrl = $data['authorization_url'] ?? null;

        $sale->forceFill([
            'status' => $authorizationUrl !== null ? LicenseSaleStatus::Pending : LicenseSaleStatus::Failed,
            'raw_response' => $response,
        ])->save();

        return ['sale' => $sale, 'authorization_url' => $authorizationUrl];
    }
}
