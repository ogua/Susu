<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Customers\AssignCustomerAgentAction;
use App\Actions\Customers\BuildCustomerOverviewAction;
use App\Actions\Customers\TransferCustomerAction;
use App\Actions\Customers\UpdateCustomerAction;
use App\Enums\AccountStatus;
use App\Enums\CustomerSegment;
use App\Enums\GroupLoanStatus;
use App\Enums\LoanStatus;
use App\Http\Controllers\Api\V1\Concerns\ScopesToAccessibleBranches;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AssignCustomerAgentRequest;
use App\Http\Requests\Api\V1\TransferCustomersRequest;
use App\Http\Requests\Api\V1\UpdateCustomerRequest;
use App\Http\Resources\V1\CustomerResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Back-office customer list + branch transfer / agent assignment
 * (branch_manager, company_admin). Company admins see every branch; managers
 * the branches they belong to (?branch_id narrows to one). Field-agent customer endpoints live under /agent.
 */
class CustomerController extends Controller
{
    use ScopesToAccessibleBranches;

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'segment' => ['nullable', Rule::enum(CustomerSegment::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'agent_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
        ]);

        $segment = CustomerSegment::tryFrom((string) $request->query('segment')) ?? CustomerSegment::All;

        $query = $segment->apply($this->scope($request))
            ->with(['branch', 'assignedAgent'])
            ->withSum(['savingsAccounts as savings_balance' => fn (Builder $accounts) => $accounts->where('status', AccountStatus::Active)], 'balance')
            ->withSum(['loans as loan_outstanding' => fn (Builder $loans) => $loans->where('status', LoanStatus::Disbursed)], 'outstanding_balance')
            ->withSum(['groupLoans as group_loan_outstanding' => fn (Builder $loans) => $loans->where('status', GroupLoanStatus::Active)], 'outstanding_balance')
            ->when($request->query('agent_id'), fn (Builder $query, string $agentId) => $query->where('assigned_agent_id', $agentId))
            ->when($request->query('branch_id'), fn (Builder $query, string $branchId) => $query->where('branch_id', $branchId))
            ->when($request->query('search'), fn (Builder $query, string $term) => $query->where(fn (Builder $match) => $match
                ->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('business_name', 'like', "%{$term}%")
                ->orWhere('customer_code', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")));

        return CustomerResource::collection($query->latest()->paginate($request->integer('per_page', 30)));
    }

    public function overview(Request $request, BuildCustomerOverviewAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($this->scope($request))]);
    }

    /** Partial update: only the fields sent change (same rules as the customer.update sync op). */
    public function update(UpdateCustomerRequest $request, string $customer, UpdateCustomerAction $action): CustomerResource
    {
        $model = $this->scope($request)->findOrFail($customer);
        Gate::authorize('update', $model);

        return CustomerResource::make($action->execute($model, $request->validated())->load('branch', 'assignedAgent'));
    }

    public function transfer(TransferCustomersRequest $request, string $customer, TransferCustomerAction $action): CustomerResource
    {
        $model = $this->scope($request)->findOrFail($customer);
        Gate::authorize('transfer', $model);

        return CustomerResource::make($action->execute(
            $model,
            $this->branch($request),
            $request->user(),
            $this->agent($request),
            $request->validated('reason'),
        )->load('branch', 'assignedAgent'));
    }

    public function bulkTransfer(TransferCustomersRequest $request, TransferCustomerAction $action): JsonResponse
    {
        $customers = $this->scope($request)->whereIn('id', $request->validated('customer_ids'))->get();

        $result = $action->executeMany($customers, $this->branch($request), $request->user(), $this->agent($request), $request->validated('reason'));

        foreach (array_diff($request->validated('customer_ids'), $customers->pluck('id')->all()) as $missingId) {
            $result['failed'][$missingId] = 'Customer not found.';
        }

        return response()->json(['data' => $result]);
    }

    public function assignAgent(AssignCustomerAgentRequest $request, string $customer, AssignCustomerAgentAction $action): CustomerResource
    {
        $model = $this->scope($request)->findOrFail($customer);
        Gate::authorize('assignAgent', $model);

        return CustomerResource::make($action->execute($model, $this->agent($request), $request->user())->load('branch', 'assignedAgent'));
    }

    /**
     * @return Builder<Customer>
     */
    private function scope(Request $request): Builder
    {
        $user = $request->user();

        return $this->scopeToBranches(Customer::query()->where('company_id', $user->company_id), $user);
    }

    private function branch(Request $request): Branch
    {
        return Branch::where('company_id', $request->user()->company_id)->findOrFail($request->validated('branch_id'));
    }

    private function agent(Request $request): ?User
    {
        $agentId = $request->validated('agent_id');

        return $agentId === null ? null : User::where('company_id', $request->user()->company_id)->findOrFail($agentId);
    }
}
