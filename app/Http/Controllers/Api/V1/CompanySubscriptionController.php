<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\InvoiceCheckoutAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CompanySubscriptionResource;
use App\Models\Company;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The signed-in company admin's subscription, usage and open invoices, and
 * Paystack payment of those invoices from the apps (same InvoiceCheckoutAction
 * as the web Billing page). The apps open the authorization URL in a browser,
 * then call verify once it closes.
 */
class CompanySubscriptionController extends Controller
{
    public function show(Request $request): CompanySubscriptionResource
    {
        return CompanySubscriptionResource::make($this->company($request)->load(['subscription.plan', 'subscriptionInvoices']));
    }

    public function checkout(Request $request, SubscriptionInvoice $invoice, InvoiceCheckoutAction $checkout): JsonResponse
    {
        $this->assertOwnInvoice($request, $invoice);

        $url = $checkout->start($invoice, $request->user(), route('billing.return'));

        return response()->json([
            'authorization_url' => $url,
            'reference' => $invoice->refresh()->provider_reference,
        ]);
    }

    public function verify(Request $request, SubscriptionInvoice $invoice, InvoiceCheckoutAction $checkout): CompanySubscriptionResource
    {
        $this->assertOwnInvoice($request, $invoice);

        if (InvoiceCheckoutAction::isInvoiceReference($invoice->provider_reference)) {
            $checkout->verifyAndComplete($invoice->provider_reference);
        }

        return $this->show($request);
    }

    private function company(Request $request): Company
    {
        $company = $request->user()->company;
        Gate::authorize('update', $company);

        return $company;
    }

    private function assertOwnInvoice(Request $request, SubscriptionInvoice $invoice): void
    {
        abort_unless($invoice->company_id === $this->company($request)->id, 404);
    }
}
