<?php

namespace App\Actions\Reports;

use App\Models\Branch;
use App\Models\Group;
use App\Models\GroupContribution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Susu (ROSCA) group health for the branch: membership, current-round
 * progress, lifetime collections, and — when a period is given — how much was
 * contributed inside it. Snapshot columns ignore the range; only
 * collected_in_period respects it, since rounds don't align to calendars.
 *
 * @phpstan-type GroupReportRow array{
 *     group: Group,
 *     members_count: int,
 *     current_round: ?int,
 *     round_expected: int,
 *     round_collected: int,
 *     lifetime_collected: int,
 *     rounds_paid_out: int,
 *     collected_in_period: int,
 * }
 * @phpstan-type GroupReportResult array{
 *     rows: Collection<int, GroupReportRow>,
 *     totalCollectedInPeriod: int,
 *     totalLifetimeCollected: int,
 *     from: ?CarbonImmutable,
 *     to: ?CarbonImmutable,
 * }
 */
class BuildGroupReportAction
{
    /**
     * @return GroupReportResult
     */
    public function execute(Branch $branch, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $groups = Group::query()
            ->where('branch_id', $branch->id)
            ->with(['members', 'rounds'])
            ->orderBy('name')
            ->get();

        $periodByGroup = GroupContribution::query()
            ->whereHas('round.group', fn ($query) => $query->where('branch_id', $branch->id))
            ->when($from, fn ($query) => $query->where('recorded_at', '>=', $from->startOfDay()))
            ->when($to, fn ($query) => $query->where('recorded_at', '<=', $to->endOfDay()))
            ->with('round:id,group_id')
            ->get()
            ->groupBy(fn (GroupContribution $contribution): string => $contribution->round->group_id)
            ->map(fn (Collection $group): int => (int) $group->sum('amount'));

        $rows = $groups->map(function (Group $group) use ($periodByGroup): array {
            $currentRound = $group->currentRound();

            return [
                'group' => $group,
                'members_count' => $group->members->count(),
                'current_round' => $currentRound?->round_number,
                'round_expected' => (int) ($currentRound?->total_expected ?? 0),
                'round_collected' => (int) ($currentRound?->total_collected ?? 0),
                'lifetime_collected' => (int) $group->rounds->sum('total_collected'),
                'rounds_paid_out' => $group->rounds->whereNotNull('paid_out_at')->count(),
                'collected_in_period' => (int) ($periodByGroup[$group->id] ?? 0),
            ];
        })->values();

        return [
            'rows' => $rows,
            'totalCollectedInPeriod' => (int) $rows->sum('collected_in_period'),
            'totalLifetimeCollected' => (int) $rows->sum('lifetime_collected'),
            'from' => $from,
            'to' => $to,
        ];
    }
}
