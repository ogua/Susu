<?php

namespace App\Actions\Reports;

use App\Enums\EntryStatus;
use App\Enums\TransactionType;
use App\Models\Branch;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Every susu collection recorded for the branch in the period, with per-agent
 * subtotals. Ranged on recorded_at (the field date), not posted_at, so
 * offline-synced collections land on the day the agent actually took the cash.
 *
 * @phpstan-type CollectionsReportResult array{
 *     entries: \Illuminate\Database\Eloquent\Collection<int, JournalEntry>,
 *     agentSubtotals: Collection<int, array{agent: string, total: int, count: int}>,
 *     totalAmount: int,
 *     totalCount: int,
 *     from: ?CarbonImmutable,
 *     to: ?CarbonImmutable,
 * }
 */
class BuildCollectionsReportAction
{
    /**
     * @return CollectionsReportResult
     */
    public function execute(Branch $branch, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $entries = JournalEntry::query()
            ->where('branch_id', $branch->id)
            ->where('type', TransactionType::Collection)
            ->whereIn('status', [EntryStatus::Completed, EntryStatus::Reversed])
            ->when($from, fn ($query) => $query->where('recorded_at', '>=', $from->startOfDay()))
            ->when($to, fn ($query) => $query->where('recorded_at', '<=', $to->endOfDay()))
            ->withSum('lines as amount_sum', 'debit')
            ->with('recordedBy')
            ->orderBy('recorded_at')
            ->get();

        $agentSubtotals = $entries
            ->groupBy(fn (JournalEntry $entry): string => $entry->recordedBy?->name ?? 'Unknown')
            ->map(fn (Collection $group, string $agent): array => [
                'agent' => $agent,
                'total' => (int) $group->sum('amount_sum'),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->values();

        return [
            'entries' => $entries,
            'agentSubtotals' => $agentSubtotals,
            'totalAmount' => (int) $entries->sum('amount_sum'),
            'totalCount' => $entries->count(),
            'from' => $from,
            'to' => $to,
        ];
    }
}
