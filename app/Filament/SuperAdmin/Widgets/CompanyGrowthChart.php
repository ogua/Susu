<?php

namespace App\Filament\SuperAdmin\Widgets;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** New and cumulative tenant companies over the last 12 months. */
class CompanyGrowthChart extends ChartWidget
{
    protected ?string $heading = 'Company Growth — Last 12 Months';

    protected static ?int $sort = 3;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths(11);

        $byMonth = Company::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->groupBy(fn (Company $company): string => $company->created_at->format('Y-m'))
            ->map(fn ($group): int => $group->count());

        $baseline = Company::query()->where('created_at', '<', $start)->count();

        $labels = [];
        $newCounts = [];
        $cumulative = [];
        $running = $baseline;

        foreach (range(0, 11) as $offset) {
            $month = $start->addMonths($offset);
            $key = $month->format('Y-m');
            $new = (int) ($byMonth[$key] ?? 0);
            $running += $new;

            $labels[] = $month->format('M Y');
            $newCounts[] = $new;
            $cumulative[] = $running;
        }

        return [
            'datasets' => [
                [
                    'label' => 'New companies',
                    'data' => $newCounts,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => '#f59e0b',
                ],
                [
                    'label' => 'Total companies',
                    'data' => $cumulative,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => '#3b82f6',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
