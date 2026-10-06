<?php

namespace App\Http\Controllers;

use App\Actions\Billing\InvoiceCheckoutAction;
use App\Enums\InvoiceStatus;
use App\Filament\Pages\Billing;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Paystack's return from a subscription-invoice checkout. Verifies with
 * Paystack (never trusting the redirect), settles the invoice, and sends the
 * company admin back to their Billing page.
 */
class BillingCallbackController extends Controller
{
    public function __invoke(Request $request, InvoiceCheckoutAction $checkout): RedirectResponse
    {
        $reference = $request->query('reference') ?? $request->query('trxref');
        $invoice = InvoiceCheckoutAction::isInvoiceReference($reference) ? $checkout->verifyAndComplete($reference) : null;

        /** @var User $user */
        $user = $request->user();
        abort_if($invoice !== null && $invoice->company_id !== $user->company_id, 404);

        $paid = $invoice?->status === InvoiceStatus::Paid;

        Notification::make()
            ->title($paid ? "Invoice {$invoice->number} paid — thank you" : 'The payment was not completed')
            ->{$paid ? 'success' : 'warning'}()
            ->send();

        $branch = $user->branches()->first();

        return redirect($branch !== null ? Billing::getUrl(panel: 'admin', tenant: $branch) : '/');
    }
}
