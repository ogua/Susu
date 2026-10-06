<?php

namespace App\Filament\SuperAdmin\Widgets;

use App\Actions\Reports\BuildCompanyActivityTrendAction;
use Filament\Widgets\ChartWidget;

/** Companies on the platform vs companies that actually transacted, per month — the retention picture. */
class CompanyActivityChart extends ChartWidget
{
    protected ?string $heading = 'Active Companies — Last 12 Months';

    protected ?string $description = 'Companies with at least one transaction in the month, against all companies on the platform.';

    protected static ?int $sort = 3;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $months = app(BuildCompanyActivityTrendAction::class)->execute();

        return [
            'datasets' => [
                [
                    'label' => 'Active companies',
                    'data' => array_column($months, 'active'),
                    'borderColor' => '#16a34a',
                    'backgroundColor' => '#16a34a',
                ],
                [
                    'label' => 'All companies',
                    'data' => array_column($months, 'total'),
                    'borderColor' => '#94a3b8',
                    'backgroundColor' => '#94a3b8',
                ],
            ],
            'labels' => array_column($months, 'label'),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
