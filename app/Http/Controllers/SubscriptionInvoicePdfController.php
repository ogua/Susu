<?php

namespace App\Http\Controllers;

use App\Actions\Billing\InvoicePdfAction;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A subscription invoice (or receipt, once paid) as a PDF. Super admins and
 * the company's own admins may download it; the apps reach it through a
 * short-lived signed URL minted by the API (no session in the app browser).
 */
class SubscriptionInvoicePdfController extends Controller
{
    public function __invoke(Request $request, SubscriptionInvoice $invoice, InvoicePdfAction $pdf): Response
    {
        if (! $request->hasValidSignature()) {
            $user = $request->user();
            abort_unless($user instanceof User, 403);
            abort_unless(
                $user->hasRole('super_admin') || ($user->hasRole('company_admin') && $user->company_id === $invoice->company_id),
                403,
            );
        }

        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->filename($invoice).'"',
        ]);
    }
}
