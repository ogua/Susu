<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Dashboard\BuildAgentDashboardAction;
use App\Actions\Dashboard\BuildBranchDashboardAction;
use App\Actions\Dashboard\BuildCustomerDashboardAction;
use App\Http\Controllers\Api\V1\Concerns\ResolvesRequestBranch;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ResolvesRequestBranch;

    public function agent(Request $request, BuildAgentDashboardAction $action): JsonResponse
    {
        return response()->json($action->execute($request->user()));
    }

    public function branch(Request $request, BuildBranchDashboardAction $action): JsonResponse
    {
        return response()->json($action->execute($this->resolveBranch($request)));
    }

    public function customer(Request $request, BuildCustomerDashboardAction $action): JsonResponse
    {
        $customer = Customer::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json($action->execute($customer));
    }
}
