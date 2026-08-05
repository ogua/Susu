<?php

namespace App\Filament\Widgets;

use App\Actions\Dashboard\BuildBranchDashboardAction;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** Daily money in (collections) vs money out (paid withdrawals), 30 days. */
class SavingsVsWithdrawalsChart extends ChartWidget
{
    protected ?string $heading = 'Savings vs Withdrawals — Last 30 Days';

    protected static ?int $sort = 6;

    protected ?string $pollingInterval = null;

    protected function getData(): array
    {
        $branch = Filament::getTenant();

        if ($branch === null) {
            return ['datasets' => [], 'labels' => []];
        }

        $series = app(BuildBranchDashboardAction::class)->execute($branch)['savings_vs_withdrawals'];

        return [
            'datasets' => [
                [
                    'label' => 'Deposits (GHS)',
                    'data' => array_map(fn (array $day): float => $day['deposits'] / 100, $series),
                    'backgroundColor' => '#10b981',
                ],
                [
                    'label' => 'Withdrawals (GHS)',
                    'data' => array_map(fn (array $day): float => $day['withdrawals'] / 100, $series),
                    'backgroundColor' => '#ef4444',
                ],
            ],
            'labels' => array_map(fn (array $day): string => $day['date'], $series),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
