<?php

namespace App\Filament\SuperAdmin\Widgets;

use App\Enums\LicenseSaleStatus;
use App\Models\DesktopLicenseSale;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** Paid/issued desktop-license revenue per month, last 12 months. */
class LicenseRevenueChart extends ChartWidget
{
    protected ?string $heading = 'License Revenue — Last 12 Months';

    protected static ?int $sort = 5;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths(11);

        $byMonth = DesktopLicenseSale::query()
            ->whereIn('status', [LicenseSaleStatus::Paid, LicenseSaleStatus::Issued])
            ->where('created_at', '>=', $start)
            ->get(['created_at', 'amount'])
            ->groupBy(fn (DesktopLicenseSale $sale): string => $sale->created_at->format('Y-m'))
            ->map(fn ($group): int => (int) $group->sum('amount'));

        $labels = [];
        $revenue = [];

        foreach (range(0, 11) as $offset) {
            $month = $start->addMonths($offset);
            $labels[] = $month->format('M Y');
            $revenue[] = ((int) ($byMonth[$month->format('Y-m')] ?? 0)) / 100;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Revenue (GHS)',
                    'data' => $revenue,
                    'backgroundColor' => '#f59e0b',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
