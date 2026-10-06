<?php

namespace App\Actions\License;

use App\Enums\LicenseSaleStatus;
use App\Mail\LicenseKeyIssuedMail;
use App\Models\DesktopLicenseSale;
use App\Services\Payments\PaystackClient;
use App\Services\Sms\SmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Confirms a license sale's payment and issues the key exactly once. Called
 * from two independent races — the browser callback (execute(), a direct
 * Paystack verify) and the webhook (complete(), an already-known payload) —
 * mirrors App\Actions\Payments\VerifyPaymentIntentAction's verify race, with
 * the same lockForUpdate + terminal-status guard.
 */
class FulfillLicenseSaleAction
{
    public function __construct(
        private PaystackClient $paystack,
        private GenerateLicenseKeyAction $generateKey,
        private SmsService $sms,
    ) {}

    /** Polls Paystack directly — used by the browser's callback redirect. */
    public function execute(DesktopLicenseSale $sale): DesktopLicenseSale
    {
        if ($this->isTerminal($sale)) {
            return $sale;
        }

        $response = $this->paystack->verify($sale->provider_reference);
        $data = $response['data'] ?? [];

        return $this->complete($sale, $data['status'] ?? 'pending', $response);
    }

    /** Applies an already-known outcome (e.g. from a webhook payload) without calling Paystack again. */
    public function complete(DesktopLicenseSale $sale, string $paystackStatus, array $rawResponse): DesktopLicenseSale
    {
        $result = DB::transaction(function () use ($sale, $paystackStatus, $rawResponse): DesktopLicenseSale {
            /** @var DesktopLicenseSale $locked */
            $locked = DesktopLicenseSale::whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($this->isTerminal($locked)) {
                return $locked;
            }

            if ($paystackStatus !== 'success') {
                $locked->forceFill(['status' => LicenseSaleStatus::Failed, 'raw_response' => $rawResponse])->save();

                return $locked;
            }

            $locked->forceFill(['status' => LicenseSaleStatus::Paid, 'raw_response' => $rawResponse])->save();

            return $this->generateKey->execute($locked);
        });

        $notified = $this->notifyOnce($result);

        return $notified ? $result->fresh() : $result;
    }

    private function isTerminal(DesktopLicenseSale $sale): bool
    {
        return in_array($sale->status, [LicenseSaleStatus::Issued, LicenseSaleStatus::Failed], true);
    }

    /**
     * Claims the notification (marks notified_at inside a lock) before
     * sending anything, so a webhook and callback racing each other can
     * never both email/text the same key — whichever completes the
     * transaction above and claims first wins; the other is a no-op.
     */
    private function notifyOnce(DesktopLicenseSale $sale): bool
    {
        if ($sale->status !== LicenseSaleStatus::Issued) {
            return false;
        }

        $claimed = DB::transaction(function () use ($sale): bool {
            $locked = DesktopLicenseSale::whereKey($sale->id)->lockForUpdate()->first();
            if ($locked === null || $locked->notified_at !== null) {
                return false;
            }

            $locked->forceFill(['notified_at' => now()])->save();

            return true;
        });

        if (! $claimed) {
            return false;
        }

        Mail::to($sale->customer_email)->send(new LicenseKeyIssuedMail($sale));

        if ($sale->customer_phone) {
            $this->sms->sendSystem(
                $sale->customer_phone,
                "Your OguaFinance Desktop activation key: {$sale->license_key}",
            );
        }

        return true;
    }
}
