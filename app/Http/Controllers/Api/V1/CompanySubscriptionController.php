<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CompanySubscriptionResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The signed-in company admin's subscription, usage and open invoices (paid on the web Billing page). */
class CompanySubscriptionController extends Controller
{
    public function show(Request $request): CompanySubscriptionResource
    {
        $company = $request->user()->company;
        Gate::authorize('update', $company);

        return CompanySubscriptionResource::make($company->load(['subscription.plan', 'subscriptionInvoices']));
    }
}
