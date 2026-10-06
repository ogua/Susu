<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\SubscriptionInvoice;
use Barryvdh\DomPDF\Facade\Pdf;

/** A subscription invoice as a PDF — a receipt once it is paid. */
class InvoicePdfAction
{
    public function render(SubscriptionInvoice $invoice): string
    {
        return Pdf::loadView('pdf.subscription-invoice', [
            'invoice' => $invoice->loadMissing(['company', 'plan']),
            'isReceipt' => $invoice->status === InvoiceStatus::Paid,
        ])->output();
    }

    public function filename(SubscriptionInvoice $invoice): string
    {
        return ($invoice->status === InvoiceStatus::Paid ? 'receipt-' : 'invoice-').$invoice->number.'.pdf';
    }
}
