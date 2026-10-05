<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Actions\LoanGroups\BuildLoanGroupHistoryAction;
use App\Actions\LoanGroups\BuildLoanGroupSummaryAction;
use App\Actions\LoanGroups\IssueLoansToGroupAction;
use App\Actions\LoanGroups\OpenSavingsForGroupAction;
use App\Actions\LoanGroups\RemoveLoanGroupMemberAction;
use App\Enums\LoanFrequency;
use App\Http\Controllers\Api\V1\Concerns\ScopesToAccessibleBranches;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddLoanGroupMemberRequest;
use App\Http\Requests\Api\V1\IssueLoansToGroupRequest;
use App\Http\Requests\Api\V1\OpenSavingsForGroupRequest;
use App\Http\Requests\Api\V1\StoreLoanGroupRequest;
use App\Http\Resources\V1\LoanGroupResource;
use App\Models\Customer;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use App\Models\SavingsProduct;
use App\Support\AgentAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/** Staff-only (field_agent/branch_manager/company_admin) — see routes/api/v1.php. */
class LoanGroupController extends Controller
{
    use ScopesToAccessibleBranches;

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = LoanGroup::with('members.customer', 'members.activeLoan', 'members.openLoan')
            ->withCount('members')
            ->withSum('activeGroupLoans as group_outstanding_sum', 'outstanding_balance')
            ->where('company_id', $user->company_id)
            ->where('branch_id', $user->branch_id)
            ->when(AgentAssignment::restricts($user), fn ($q) => AgentAssignment::scopeGroups($q, $user));

        return LoanGroupResource::collection($query->latest()->paginate($request->integer('per_page', 30)));
    }

    public function show(Request $request, string $loanGroup, BuildLoanGroupSummaryAction $summary): LoanGroupResource
    {
        $model = LoanGroup::withCount('members')
            ->withSum('activeGroupLoans as group_outstanding_sum', 'outstanding_balance')
            ->where('company_id', $request->user()->company_id)
            ->whereIn('branch_id', $request->user()->accessibleBranchIds())
            ->when(AgentAssignment::restricts($request->user()), fn ($q) => AgentAssignment::scopeGroups($q, $request->user()))
            ->findOrFail($loanGroup);

        return LoanGroupResource::make($model->load('members.customer.savingsAccounts', 'members.activeLoan', 'members.openLoan'))
            ->additional(['summary' => $summary->execute($model)]);
    }

    public function history(Request $request, string $loanGroup, BuildLoanGroupHistoryAction $action): JsonResponse
    {
        $model = $this->scopeToBranches(LoanGroup::where('company_id', $request->user()->company_id), $request->user())
            ->when(AgentAssignment::restricts($request->user()), fn ($q) => AgentAssignment::scopeGroups($q, $request->user()))
            ->findOrFail($loanGroup);

        return response()->json(['data' => $action->execute($model, min($request->integer('limit', 200), 500))]);
    }

    /** "Apply a loan to the group" — same terms to every active member (or member_ids). */
    public function issueLoans(IssueLoansToGroupRequest $request, string $loanGroup, IssueLoansToGroupAction $action): JsonResponse
    {
        $group = $this->scopeToBranches(LoanGroup::where('company_id', $request->user()->company_id), $request->user())->findOrFail($loanGroup);

        $result = $action->execute(
            issuedBy: $request->user(),
            loanGroup: $group,
            principal: $request->integer('principal_amount'),
            securityDeposit: $request->integer('security_deposit_amount'),
            periodicAmount: $request->integer('periodic_amount'),
            frequency: LoanFrequency::from($request->validated('repayment_frequency')),
            startDate: Carbon::parse($request->validated('start_date')),
            memberIds: $request->validated('member_ids'),
            notes: $request->validated('notes'),
        );

        return response()->json(['data' => $result], 201);
    }

    /** "Apply a saving to the group" — open a daily-susu account for every member lacking one. */
    public function openSavings(OpenSavingsForGroupRequest $request, string $loanGroup, OpenSavingsForGroupAction $action): JsonResponse
    {
        $user = $request->user();
        $group = $this->scopeToBranches(LoanGroup::where('company_id', $user->company_id), $user)->findOrFail($loanGroup);
        $product = SavingsProduct::where('company_id', $user->company_id)->findOrFail($request->validated('savings_product_id'));

        return response()->json(['data' => $action->execute($group, $product, contributionAmount: $request->validated('contribution_amount'))], 201);
    }

    public function store(StoreLoanGroupRequest $request): JsonResponse
    {
        $user = $request->user();

        $loanGroup = LoanGroup::create([
            'company_id' => $user->company_id,
            'branch_id' => $user->branch_id,
            'created_by' => $user->id,
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'is_active' => true,
        ]);

        return LoanGroupResource::make($loanGroup)->response()->setStatusCode(201);
    }

    public function storeMember(AddLoanGroupMemberRequest $request, string $loanGroup, AddLoanGroupMemberAction $action): JsonResponse
    {
        $user = $request->user();

        $group = $this->scopeToBranches(LoanGroup::where('company_id', $user->company_id), $user)->findOrFail($loanGroup);
        $customer = $this->scopeToBranches(Customer::where('company_id', $user->company_id), $user)->findOrFail($request->validated('customer_id'));

        $action->execute($group, $customer);

        return LoanGroupResource::make($group->fresh()->load('members.customer', 'members.activeLoan', 'members.openLoan'))
            ->response()->setStatusCode(201);
    }

    public function destroyMember(Request $request, string $loanGroup, string $member, RemoveLoanGroupMemberAction $action): LoanGroupResource
    {
        $user = $request->user();

        $group = $this->scopeToBranches(LoanGroup::where('company_id', $user->company_id), $user)->findOrFail($loanGroup);
        $memberModel = LoanGroupMember::where('loan_group_id', $group->id)->findOrFail($member);

        $action->execute($memberModel);

        return LoanGroupResource::make($group->fresh()->load('members.customer', 'members.activeLoan', 'members.openLoan'));
    }
}
