<?php

namespace App\Actions\Reports;

use App\Enums\EntryStatus;
use App\Models\Company;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Retention view: per month, how many companies existed, how many actually
 * transacted (any completed journal entry), and how many were new. The gap
 * between "on the platform" and "active" is the churn risk.
 *
 * @phpstan-type ActivityMonth array{month: string, label: string, total: int, active: int, new: int, active_percent: float}
 */
class BuildCompanyActivityTrendAction
{
    /**
     * @return list<ActivityMonth>
     */
    public function execute(int $monthCount = 12): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($monthCount - 1);

        $monthExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', recorded_at)"
            : "DATE_FORMAT(recorded_at, '%Y-%m')";

        $activeByMonth = JournalEntry::query()
            ->where('status', EntryStatus::Completed)
            ->where('recorded_at', '>=', $start)
            ->selectRaw("{$monthExpression} as month, count(distinct company_id) as companies")
            ->groupByRaw($monthExpression)
            ->pluck('companies', 'month');

        $createdDates = Company::query()->pluck('created_at');

        $months = [];
        foreach (range(0, $monthCount - 1) as $offset) {
            $month = $start->addMonths($offset);
            $key = $month->format('Y-m');
            $total = $createdDates->filter(fn ($created): bool => $created < $month->endOfMonth())->count();
            $active = (int) ($activeByMonth[$key] ?? 0);

            $months[] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'total' => $total,
                'active' => $active,
                'new' => $createdDates->filter(fn ($created): bool => $created->format('Y-m') === $key)->count(),
                'active_percent' => $total > 0 ? round($active / $total * 100, 1) : 0.0,
            ];
        }

        return $months;
    }
}
