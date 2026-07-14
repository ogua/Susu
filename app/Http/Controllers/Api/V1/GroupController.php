<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Groups\PayoutGroupRoundAction;
use App\Actions\Groups\RecordGroupContributionAction;
use App\Enums\ClientOrigin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PayoutGroupRoundRequest;
use App\Http\Requests\Api\V1\StoreGroupContributionRequest;
use App\Http\Resources\V1\GroupResource;
use App\Models\Customer;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupRound;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Shared by agent (records contributions in the field) and customer
 * (views their own memberships) roles — mirrors LoanController's
 * shared-endpoint pattern. Payouts are staff-only at the route level.
 */
class GroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Group::with('members.customer')->where('company_id', $user->company_id);
        $query = $user->hasRole('customer')
            ? $query->whereHas('members', fn ($q) => $q->where('customer_id', $this->customerFor($user)->id))
            : $query->where('branch_id', $user->branch_id);

        return GroupResource::collection($query->latest()->paginate($request->integer('per_page', 30)));
    }

    public function show(Request $request, string $group): GroupResource
    {
        $model = $this->findScoped($request, $group);

        return GroupResource::make($model->load('members.customer', 'rounds.payoutMember.customer'));
    }

    public function storeContribution(StoreGroupContributionRequest $request, string $group, RecordGroupContributionAction $action): JsonResponse
    {
        $model = $this->findScoped($request, $group);
        $member = GroupMember::where('group_id', $model->id)->findOrFail($request->validated('group_member_id'));

        $contribution = $action->execute(
            recordedBy: $request->user(),
            member: $member,
            clientReference: $request->validated('client_reference'),
            origin: ClientOrigin::Mobile,
        );

        return response()->json([
            'contribution_id' => $contribution->id,
            'amount' => $contribution->amount,
            'group' => GroupResource::make($model->fresh()->load('rounds')),
        ], 201);
    }

    public function payout(PayoutGroupRoundRequest $request, string $round, PayoutGroupRoundAction $action): JsonResponse
    {
        $model = GroupRound::whereHas('group', fn ($q) => $q->where('company_id', $request->user()->company_id))
            ->findOrFail($round);

        $paid = $action->execute($model, $request->user(), override: (bool) $request->validated('override', false));

        return response()->json([
            'round_id' => $paid->id,
            'status' => $paid->status->value,
            'payout_entry_id' => $paid->payout_entry_id,
        ]);
    }

    private function findScoped(Request $request, string $id): Group
    {
        $user = $request->user();
        $query = Group::where('company_id', $user->company_id);

        if ($user->hasRole('customer')) {
            $query->whereHas('members', fn ($q) => $q->where('customer_id', $this->customerFor($user)->id));
        }

        return $query->findOrFail($id);
    }

    private function customerFor(User $user): Customer
    {
        return Customer::where('user_id', $user->id)->firstOrFail();
    }
}
