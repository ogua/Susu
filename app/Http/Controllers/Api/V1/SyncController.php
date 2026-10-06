<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sync\ProcessSyncBatchAction;
use App\Enums\ClientOrigin;
use App\Enums\GroupLoanStatus;
use App\Enums\GroupStatus;
use App\Enums\LoanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncBatchRequest;
use App\Http\Resources\V1\CustomerResource;
use App\Http\Resources\V1\GroupLoanResource;
use App\Http\Resources\V1\GroupResource;
use App\Http\Resources\V1\LoanGroupResource;
use App\Http\Resources\V1\LoanProductResource;
use App\Http\Resources\V1\LoanResource;
use App\Http\Resources\V1\SavingsAccountResource;
use App\Http\Resources\V1\SavingsProductResource;
use App\Models\Customer;
use App\Models\Group;
use App\Models\GroupLoan;
use App\Models\Loan;
use App\Models\LoanGroup;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Support\AgentAssignment;
use App\Support\StaffBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SyncController extends Controller
{
    public function batch(SyncBatchRequest $request, ProcessSyncBatchAction $action): JsonResponse
    {
        $origin = ClientOrigin::tryFrom($request->header('X-Client-Origin', 'mobile')) ?? ClientOrigin::Mobile;

        $results = $action->execute($request->user(), $origin, $request->validated('ops'));

        return response()->json(['results' => $results]);
    }

    /**
     * Full snapshot of the caller's working set for the local database: a
     * field agent's own accounts, or a manager's/admin's working branch
     * (?branch_id to pick another they can access). Loans and group loans
     * follow the working set's customers; susu and customer groups follow its
     * branches. Only open records are sent here — delta() carries closures.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();

        $accounts = $this->workingAccounts($request)
            ->with('product')
            ->get();

        $customers = Customer::whereIn('id', $accounts->pluck('customer_id'))->get();

        // Company-wide, not agent-scoped: offline clients (desktop hybrid mode) need
        // the full active catalogue to keep their local product ids server-matching
        // (see App\Http\Resources\V1\SavingsProductResource).
        $products = SavingsProduct::where('company_id', $user->company_id)->where('is_active', true)->get();

        $customerIds = $accounts->pluck('customer_id')->unique()->values();
        $branchIds = $accounts->pluck('branch_id')->unique()->values();

        return response()->json([
            'accounts' => SavingsAccountResource::collection($accounts),
            'customers' => CustomerResource::collection($customers),
            'products' => SavingsProductResource::collection($products),
            'loan_products' => LoanProductResource::collection(
                LoanProduct::where('company_id', $user->company_id)->where('is_active', true)->get()
            ),
            'loans' => LoanResource::collection(
                $this->loans($user->company_id, $customerIds)
                    ->whereIn('status', [LoanStatus::Applied, LoanStatus::Approved, LoanStatus::Disbursed])
                    ->get()
            ),
            'group_loans' => GroupLoanResource::collection(
                $this->groupLoans($user->company_id, $customerIds)
                    ->whereIn('status', [GroupLoanStatus::Draft, GroupLoanStatus::Active])
                    ->get()
            ),
            'groups' => GroupResource::collection(
                $this->groups($user->company_id, $branchIds)
                    ->whereIn('status', [GroupStatus::Draft, GroupStatus::Active])
                    ->get()
            ),
            'loan_groups' => LoanGroupResource::collection(
                $this->loanGroups($user->company_id, $branchIds)->where('is_active', true)->get()
            ),
            'cursor' => now()->toISOString(),
        ]);
    }

    /** Changes since the cursor, for incremental refreshes. */
    public function delta(Request $request): JsonResponse
    {
        $validated = $request->validate(['cursor' => ['required', 'date']]);
        $since = Carbon::parse($validated['cursor']);

        $accounts = $this->workingAccounts($request)
            ->where('updated_at', '>', $since)
            ->with('product')
            ->get();

        $customers = Customer::query()
            ->whereIn('id', $this->workingAccounts($request)->select('customer_id'))
            ->where('updated_at', '>', $since)
            ->get();

        $companyId = $request->user()->company_id;
        $customerIds = $this->workingAccounts($request)->distinct()->pluck('customer_id');
        $branchIds = $this->workingAccounts($request)->distinct()->pluck('branch_id');
        $changed = fn (Builder $query): Builder => $query->where('updated_at', '>', $since);

        return response()->json([
            'accounts' => SavingsAccountResource::collection($accounts),
            'customers' => CustomerResource::collection($customers),
            // Any status, so a closed/written-off/cancelled record reaches the device.
            'loans' => LoanResource::collection(
                $this->loans($companyId, $customerIds)
                    ->where(fn (Builder $query) => $changed($query)->orWhereHas('installments', $changed))
                    ->get()
            ),
            'group_loans' => GroupLoanResource::collection(
                $this->groupLoans($companyId, $customerIds)
                    ->where(fn (Builder $query) => $changed($query)->orWhereHas('installments', $changed))
                    ->get()
            ),
            'groups' => GroupResource::collection(
                $this->groups($companyId, $branchIds)
                    ->where(fn (Builder $query) => $changed($query)
                        ->orWhereHas('members', $changed)
                        ->orWhereHas('rounds', $changed))
                    ->get()
            ),
            'loan_groups' => LoanGroupResource::collection(
                $this->loanGroups($companyId, $branchIds)
                    ->where(fn (Builder $query) => $changed($query)->orWhereHas('members', $changed))
                    ->get()
            ),
            'cursor' => now()->toISOString(),
        ]);
    }

    /**
     * @param  iterable<int, string>  $customerIds
     * @return Builder<Loan>
     */
    private function loans(string $companyId, iterable $customerIds): Builder
    {
        return Loan::where('company_id', $companyId)
            ->whereIn('customer_id', $customerIds)
            ->with(['loanProduct', 'installments']);
    }

    /**
     * @param  iterable<int, string>  $customerIds
     * @return Builder<GroupLoan>
     */
    private function groupLoans(string $companyId, iterable $customerIds): Builder
    {
        return GroupLoan::where('company_id', $companyId)
            ->whereIn('customer_id', $customerIds)
            ->with(['loanGroup', 'installments']);
    }

    /**
     * @param  iterable<int, string>  $branchIds
     * @return Builder<Group>
     */
    private function groups(string $companyId, iterable $branchIds): Builder
    {
        return Group::where('company_id', $companyId)
            ->whereIn('branch_id', $branchIds)
            ->with(['members', 'rounds']);
    }

    /**
     * @param  iterable<int, string>  $branchIds
     * @return Builder<LoanGroup>
     */
    private function loanGroups(string $companyId, iterable $branchIds): Builder
    {
        return LoanGroup::where('company_id', $companyId)
            ->whereIn('branch_id', $branchIds)
            ->with('members');
    }

    /**
     * @return Builder<SavingsAccount>
     */
    private function workingAccounts(Request $request): Builder
    {
        $request->validate(['branch_id' => ['nullable', 'uuid']]);
        $user = $request->user();
        $branchIds = AgentAssignment::restricts($user) ? null : [StaffBranch::resolve($user, $request->query('branch_id'))->id];

        return AgentAssignment::scopeAccounts(SavingsAccount::where('company_id', $user->company_id), $user, $branchIds);
    }
}
