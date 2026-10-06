<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\InvoiceCheckoutAction;
use App\Actions\Billing\SubscribeCompanyAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CompanySubscriptionResource;
use App\Http\Resources\V1\PlanResource;
use App\Models\Company;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

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

    /** Plans the company can switch to (active ones, cheapest first). */
    public function plans(Request $request): AnonymousResourceCollection
    {
        $this->company($request);

        return PlanResource::collection(Plan::query()->where('is_active', true)->orderBy('sort')->orderBy('price_amount')->get());
    }

    /** Self-service plan change (see SubscribeCompanyAction for proration and limits). */
    public function changePlan(Request $request, SubscribeCompanyAction $subscribe): CompanySubscriptionResource
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'uuid', Rule::exists('plans', 'id')->where('is_active', true)],
        ]);

        $subscribe->execute($this->company($request), Plan::findOrFail($validated['plan_id']));

        return $this->show($request);
    }

    /** A 10-minute signed link to the invoice PDF, for the app's in-app browser. */
    public function pdfUrl(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        $this->assertOwnInvoice($request, $invoice);

        return response()->json([
            'url' => URL::temporarySignedRoute('billing.invoices.pdf', now()->addMinutes(10), ['invoice' => $invoice]),
        ]);
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
