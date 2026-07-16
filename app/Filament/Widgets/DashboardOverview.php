<?php

namespace App\Filament\Widgets;

use App\Actions\Dashboard\BuildBranchDashboardAction;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Thin presenter over BuildBranchDashboardAction — the same numbers the
 * chart widgets and /api/v1/dashboard/branch serve, so every platform agrees.
 */
class DashboardOverview extends StatsOverviewWidget
{
    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $branch = Filament::getTenant();

        if ($branch === null) {
            return [];
        }

        $stats = app(BuildBranchDashboardAction::class)->execute($branch)['stats'];

        return [
            Stat::make('Collections Today', Money::format($stats['collections_today']))
                ->description($stats['collections_count_today'].' collection(s) recorded')
                ->icon('heroicon-o-banknotes')
                ->color('success'),
            Stat::make('Active Accounts', (string) $stats['active_accounts'])
                ->icon('heroicon-o-users')
                ->color('info'),
            Stat::make('Cash In Field', Money::format($stats['cash_in_field']))
                ->description('Total agent cash-in-hand')
                ->icon('heroicon-o-wallet')
                ->color('warning'),
            Stat::make('Portfolio At Risk', $stats['par_percent'].'%')
                ->description(Money::format($stats['at_risk']).' of '.Money::format($stats['outstanding']).' outstanding')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($stats['par_percent'] > 10 ? 'danger' : 'success'),
        ];
    }
}
