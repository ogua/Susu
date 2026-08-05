<?php

namespace App\Filament\Widgets;

use App\Actions\Dashboard\BuildBranchDashboardAction;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** Branch loan book by status — counts, colored to match the status badges. */
class LoanPortfolioChart extends ChartWidget
{
    protected ?string $heading = 'Loan Portfolio by Status';

    protected static ?int $sort = 5;

    protected ?string $pollingInterval = null;

    private const STATUS_COLORS = [
        'applied' => '#3b82f6',
        'approved' => '#8b5cf6',
        'rejected' => '#6b7280',
        'disbursed' => '#f59e0b',
        'closed' => '#10b981',
        'written_off' => '#ef4444',
    ];

    protected function getData(): array
    {
        $branch = Filament::getTenant();

        if ($branch === null) {
            return ['datasets' => [], 'labels' => []];
        }

        $breakdown = app(BuildBranchDashboardAction::class)->execute($branch)['loan_status_breakdown'];

        return [
            'datasets' => [
                [
                    'label' => 'Loans',
                    'data' => array_map(fn (array $row): int => $row['count'], $breakdown),
                    'backgroundColor' => array_map(
                        fn (array $row): string => self::STATUS_COLORS[$row['status']] ?? '#9ca3af',
                        $breakdown,
                    ),
                ],
            ],
            'labels' => array_map(
                fn (array $row): string => ucwords(str_replace('_', ' ', $row['status'])),
                $breakdown,
            ),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
