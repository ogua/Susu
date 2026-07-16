<?php

namespace App\Actions\License;

use App\Enums\LicenseSaleStatus;
use App\Models\DesktopLicenseSale;
use App\Services\License\LicenseSigningService;

/**
 * Signs and stores the activation key for a sale — idempotent so a webhook
 * and the browser callback racing each other (same as the susu payment
 * flow's verify race) never issue two different keys for one sale.
 */
class GenerateLicenseKeyAction
{
    public function __construct(private LicenseSigningService $signer) {}

    public function execute(DesktopLicenseSale $sale): DesktopLicenseSale
    {
        if ($sale->status === LicenseSaleStatus::Issued && $sale->license_key !== null) {
            return $sale;
        }

        $expiresAt = now()->addDays($sale->duration_days);
        $key = $this->signer->sign($sale->install_id, $expiresAt);

        $sale->forceFill([
            'license_key' => $key,
            'expires_at' => $expiresAt,
            'status' => LicenseSaleStatus::Issued,
        ])->save();

        return $sale;
    }
}
