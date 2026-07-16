<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Dashboard\BuildAgentDashboardAction;
use App\Actions\Dashboard\BuildBranchDashboardAction;
use App\Actions\Dashboard\BuildCustomerDashboardAction;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
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

    /**
     * Company admins may look at any of their branches via ?branch_id;
     * everyone else is pinned to their own branch.
     */
    private function resolveBranch(Request $request): Branch
    {
        $user = $request->user();

        $validated = $request->validate([
            'branch_id' => ['nullable', 'uuid'],
        ]);

        if (isset($validated['branch_id']) && $user->hasRole('company_admin')) {
            return Branch::query()
                ->where('company_id', $user->company_id)
                ->findOrFail($validated['branch_id']);
        }

        return Branch::findOrFail($user->branch_id);
    }
}
