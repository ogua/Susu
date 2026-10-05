<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Company\UpdateCompanyIntegrationSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateCompanyIntegrationSettingsRequest;
use App\Http\Resources\V1\CompanyIntegrationSettingsResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The signed-in company admin's own SMS and Paystack connection. */
class CompanyIntegrationController extends Controller
{
    public function show(Request $request): CompanyIntegrationSettingsResource
    {
        $company = $request->user()->company;
        Gate::authorize('update', $company);

        return CompanyIntegrationSettingsResource::make($company->load(['smsSetting', 'paymentSetting']));
    }

    public function update(
        UpdateCompanyIntegrationSettingsRequest $request,
        UpdateCompanyIntegrationSettingsAction $action,
    ): CompanyIntegrationSettingsResource {
        return CompanyIntegrationSettingsResource::make(
            $action->execute($request->user()->company, $request->validated()),
        );
    }
}
