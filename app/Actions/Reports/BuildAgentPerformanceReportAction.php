<?php

namespace App\Actions\Reports;

use App\Enums\AgentSummaryStatus;
use App\Enums\EntryStatus;
use App\Enums\TransactionType;
use App\Models\AgentDailySummary;
use App\Models\Branch;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Per-agent performance over a period: day sheets worked, collections taken,
 * cash variance, and commission earned for the company. Commission entries
 * are attributed through recorded_by — RecordCollectionAction posts the cycle
 * commission entry as the collecting agent.
 *
 * @phpstan-type AgentPerformanceRow array{
 *     agent: string,
 *     days_worked: int,
 *     collections_total: int,
 *     collections_count: int,
 *     variance_total: int,
 *     unreconciled_days: int,
 *     commission_total: int,
 * }
 * @phpstan-type AgentPerformanceResult array{
 *     rows: Collection<int, AgentPerformanceRow>,
 *     totals: array{collections_total: int, collections_count: int, variance_total: int, commission_total: int},
 *     from: ?CarbonImmutable,
 *     to: ?CarbonImmutable,
 * }
 */
class BuildAgentPerformanceReportAction
{
    /**
     * @return AgentPerformanceResult
     */
    public function execute(Branch $branch, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $summaries = AgentDailySummary::query()
            ->where('branch_id', $branch->id)
            ->when($from, fn ($query) => $query->where('summary_date', '>=', $from->toDateString()))
            ->when($to, fn ($query) => $query->where('summary_date', '<=', $to->toDateString()))
            ->with('agent')
            ->get();

        $commissionByAgent = JournalEntry::query()
            ->where('branch_id', $branch->id)
            ->where('type', TransactionType::Commission)
            ->whereIn('status', [EntryStatus::Completed, EntryStatus::Reversed])
            ->when($from, fn ($query) => $query->where('recorded_at', '>=', $from->startOfDay()))
            ->when($to, fn ($query) => $query->where('recorded_at', '<=', $to->endOfDay()))
            ->withSum('lines as amount_sum', 'debit')
            ->get()
            ->groupBy('recorded_by')
            ->map(fn (Collection $group): int => (int) $group->sum('amount_sum'));

        $rows = $summaries
            ->groupBy('agent_id')
            ->map(fn (Collection $group, string $agentId): array => [
                'agent' => $group->first()->agent?->name ?? 'Unknown',
                'days_worked' => $group->count(),
                'collections_total' => (int) $group->sum('collections_total'),
                'collections_count' => (int) $group->sum('collections_count'),
                'variance_total' => (int) $group->sum('variance'),
                'unreconciled_days' => $group->filter(
                    fn (AgentDailySummary $summary): bool => $summary->status !== AgentSummaryStatus::Reconciled
                )->count(),
                'commission_total' => (int) ($commissionByAgent[$agentId] ?? 0),
            ])
            ->sortByDesc('collections_total')
            ->values();

        return [
            'rows' => $rows,
            'totals' => [
                'collections_total' => (int) $rows->sum('collections_total'),
                'collections_count' => (int) $rows->sum('collections_count'),
                'variance_total' => (int) $rows->sum('variance_total'),
                'commission_total' => (int) $rows->sum('commission_total'),
            ],
            'from' => $from,
            'to' => $to,
        ];
    }
}
