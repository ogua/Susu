<?php

namespace App\Actions\Reports;

use App\Enums\WithdrawalStatus;
use App\Models\Branch;
use App\Models\WithdrawalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Every withdrawal request for the branch in the period (ranged on the
 * request date), with per-status totals including early-withdrawal penalties.
 *
 * @phpstan-type WithdrawalsReportResult array{
 *     requests: \Illuminate\Database\Eloquent\Collection<int, WithdrawalRequest>,
 *     statusTotals: Collection<int, array{status: WithdrawalStatus, count: int, amount: int, penalty: int}>,
 *     totalAmount: int,
 *     totalPenalty: int,
 *     from: ?CarbonImmutable,
 *     to: ?CarbonImmutable,
 *     status: ?WithdrawalStatus,
 * }
 */
class BuildWithdrawalsReportAction
{
    /**
     * @return WithdrawalsReportResult
     */
    public function execute(
        Branch $branch,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?WithdrawalStatus $status = null,
    ): array {
        $requests = WithdrawalRequest::query()
            ->where('branch_id', $branch->id)
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to->endOfDay()))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->with(['savingsAccount', 'customer', 'requestedBy', 'approvedBy'])
            ->orderBy('created_at')
            ->get();

        $statusTotals = $requests
            ->groupBy(fn (WithdrawalRequest $request): string => $request->status->value)
            ->map(fn (Collection $group): array => [
                'status' => $group->first()->status,
                'count' => $group->count(),
                'amount' => (int) $group->sum('amount'),
                'penalty' => (int) $group->sum('penalty_amount'),
            ])
            ->values();

        return [
            'requests' => $requests,
            'statusTotals' => $statusTotals,
            'totalAmount' => (int) $requests->sum('amount'),
            'totalPenalty' => (int) $requests->sum('penalty_amount'),
            'from' => $from,
            'to' => $to,
            'status' => $status,
        ];
    }
}
