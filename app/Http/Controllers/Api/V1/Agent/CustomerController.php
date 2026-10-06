<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Actions\Customers\CreateCustomerAction;
use App\Http\Controllers\Api\V1\Concerns\ScopesToAccessibleBranches;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCustomerRequest;
use App\Http\Resources\V1\CustomerResource;
use App\Models\Customer;
use App\Support\StaffBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ScopesToAccessibleBranches;

    public function store(StoreCustomerRequest $request, CreateCustomerAction $action): JsonResponse
    {
        $customer = $action->execute(
            $request->user(),
            StaffBranch::resolve($request->user(), $request->validated('branch_id')),
            $request->safe()->except(['client_reference', 'branch_id']),
            $request->validated('client_reference'),
        );

        return CustomerResource::make($customer)->response()->setStatusCode(201);
    }

    public function show(Request $request, Customer $customer): CustomerResource
    {
        abort_unless($customer->company_id === $request->user()->company_id && $this->canSeeBranch($request->user(), $customer->branch_id), 404);

        return CustomerResource::make($customer->load('savingsAccounts.product'));
    }
}
