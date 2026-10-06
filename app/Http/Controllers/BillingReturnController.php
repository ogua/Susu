<?php

namespace App\Http\Controllers;

use App\Actions\Billing\InvoiceCheckoutAction;
use App\Enums\InvoiceStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Where Paystack lands after an invoice is paid from the mobile or desktop
 * app — a browser with no session. It only verifies the reference with
 * Paystack (safe to do unauthenticated) and tells the payer to go back to
 * the app, which then calls the verify endpoint itself.
 */
class BillingReturnController extends Controller
{
    public function __invoke(Request $request, InvoiceCheckoutAction $checkout): View
    {
        $reference = $request->query('reference') ?? $request->query('trxref');
        $invoice = InvoiceCheckoutAction::isInvoiceReference($reference) ? $checkout->verifyAndComplete($reference) : null;

        return view('billing.return', [
            'paid' => $invoice?->status === InvoiceStatus::Paid,
            'number' => $invoice?->number,
        ]);
    }
}
