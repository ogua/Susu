<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Collections\BuildCollectionSheetAction;
use App\Actions\Collections\PostCollectionSheetAction;
use App\Enums\ClientOrigin;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PostCollectionSheetRequest;
use App\Models\Branch;
use App\Models\LoanGroup;
use App\Models\User;
use App\Support\AgentAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * "Enter Transaction" for the mobile/desktop clients. Field agents only see
 * their own customers (officer forced to themselves, with or without a
 * group); company admins may pass branch_id.
 */
class CollectionSheetController extends Controller
{
    public function show(Request $request, BuildCollectionSheetAction $action): JsonResponse
    {
        $request->validate([
            'date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'uuid'],
            'loan_group_id' => ['nullable', 'uuid'],
            'officer_id' => ['nullable', 'uuid'],
            'all_customers' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $branch = $user->hasRole('company_admin') && $request->filled('branch_id')
            ? Branch::where('company_id', $user->company_id)->findOrFail($request->query('branch_id'))
            : $user->branch;

        $group = $request->filled('loan_group_id')
            ? LoanGroup::where('branch_id', $branch->id)->findOrFail($request->query('loan_group_id'))
            : null;

        $officer = match (true) {
            AgentAssignment::restricts($user) => $user,
            $request->filled('officer_id') => User::where('company_id', $user->company_id)->findOrFail($request->query('officer_id')),
            default => null,
        };

        $date = Carbon::parse($request->query('date', now()->toDateString()));

        return response()->json([
            'data' => $action->execute($branch, $date, $group, $officer, $request->boolean('all_customers'), $request->query('search')),
            'meta' => [
                'branch_id' => $branch->id,
                'date' => $date->toDateString(),
                'loan_group_id' => $group?->id,
                'officer_id' => $officer?->id,
                'customer_limit' => BuildCollectionSheetAction::CUSTOMER_LIMIT,
            ],
        ]);
    }

    public function store(PostCollectionSheetRequest $request, PostCollectionSheetAction $action): JsonResponse
    {
        $totals = $action->execute(
            $request->user(),
            $request->validated('entries'),
            Carbon::parse($request->validated('date')),
            PaymentMethod::from($request->validated('payment_method') ?? 'cash'),
            ClientOrigin::from($request->validated('origin') ?? 'mobile'),
        );

        return response()->json(['data' => $totals], 201);
    }
}
