<?php

namespace App\Actions\Billing;

use App\Models\SubscriptionInvoice;
use App\Notifications\SubscriptionInvoiceNotice;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a billing notice for an invoice to the company's active company
 * admins (email + notification bell), or to the company's contact email
 * when it has none. Archived companies are not written to.
 */
class NotifyInvoiceAction
{
    public function execute(SubscriptionInvoice $invoice, string $type): void
    {
        $company = $invoice->company;

        if ($company === null || $company->archived_at !== null) {
            return;
        }

        $notice = new SubscriptionInvoiceNotice($invoice, $type);
        $admins = $company->activeCompanyAdmins()->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, $notice);

            return;
        }

        if (filled($company->contact_email)) {
            Notification::route('mail', $company->contact_email)->notify($notice);
        }
    }
}
