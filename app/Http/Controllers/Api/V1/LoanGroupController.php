<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Actions\LoanGroups\RemoveLoanGroupMemberAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddLoanGroupMemberRequest;
use App\Http\Requests\Api\V1\StoreLoanGroupRequest;
use App\Http\Resources\V1\LoanGroupResource;
use App\Models\Customer;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Staff-only (field_agent/branch_manager/company_admin) — see routes/api/v1.php. */
class LoanGroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = LoanGroup::with('members.customer', 'members.activeLoan')
            ->withCount('members')
            ->withSum('activeGroupLoans as group_outstanding_sum', 'outstanding_balance')
            ->where('company_id', $user->company_id)
            ->where('branch_id', $user->branch_id);

        return LoanGroupResource::collection($query->latest()->paginate($request->integer('per_page', 30)));
    }

    public function show(Request $request, string $loanGroup): LoanGroupResource
    {
        $model = LoanGroup::withCount('members')
            ->withSum('activeGroupLoans as group_outstanding_sum', 'outstanding_balance')
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($loanGroup);

        return LoanGroupResource::make($model->load('members.customer', 'members.activeLoan'));
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

        $group = LoanGroup::where('company_id', $user->company_id)->findOrFail($loanGroup);
        $customer = Customer::where('company_id', $user->company_id)->findOrFail($request->validated('customer_id'));

        $action->execute($group, $customer);

        return LoanGroupResource::make($group->fresh()->load('members.customer', 'members.activeLoan'))
            ->response()->setStatusCode(201);
    }

    public function destroyMember(Request $request, string $loanGroup, string $member, RemoveLoanGroupMemberAction $action): LoanGroupResource
    {
        $user = $request->user();

        $group = LoanGroup::where('company_id', $user->company_id)->findOrFail($loanGroup);
        $memberModel = LoanGroupMember::where('loan_group_id', $group->id)->findOrFail($member);

        $action->execute($memberModel);

        return LoanGroupResource::make($group->fresh()->load('members.customer', 'members.activeLoan'));
    }
}
