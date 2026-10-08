<?php

namespace App\Observers;

use App\Jobs\Ussd\ReportUssdTransactionStatus;
use App\Models\PaymentIntent;
use App\Models\UssdPaymentReport;

/**
 * When a USSD-started MoMo charge reaches a final status (webhook, verify or the
 * charge call itself), queue the report back to the Ogua USSD platform.
 */
class UssdPaymentIntentObserver
{
    public function updated(PaymentIntent $intent): void
    {
        if (! $intent->wasChanged('status') || ! $intent->status->isTerminal() || $intent->client_reference === null) {
            return;
        }

        $isPendingUssdReport = UssdPaymentReport::query()
            ->whereKey($intent->client_reference)
            ->whereNull('reported_at')
            ->exists();

        if ($isPendingUssdReport) {
            ReportUssdTransactionStatus::dispatch($intent->client_reference)->afterCommit();
        }
    }
}
