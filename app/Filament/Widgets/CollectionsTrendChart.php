<?php

namespace App\Filament\Widgets;

use App\Actions\Dashboard\BuildBranchDashboardAction;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** 30-day branch collections line — same series as /api/v1/dashboard/branch. */
class CollectionsTrendChart extends ChartWidget
{
    protected ?string $heading = 'Collections — Last 30 Days';

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $branch = Filament::getTenant();

        if ($branch === null) {
            return ['datasets' => [], 'labels' => []];
        }

        $trend = app(BuildBranchDashboardAction::class)->execute($branch)['collections_trend'];

        return [
            'datasets' => [
                [
                    'label' => 'Collections (GHS)',
                    'data' => array_map(fn (array $day): float => $day['total'] / 100, $trend),
                    'fill' => 'start',
                    'tension' => 0.3,
                ],
            ],
            'labels' => array_map(fn (array $day): string => $day['date'], $trend),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
