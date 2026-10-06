<?php

namespace App\Filament\SuperAdmin\Widgets;

use App\Filament\SuperAdmin\Pages\Devices;
use App\Models\SyncOp;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Last-7-days sync picture for the Sync Health page. Only shown there
 * (isDiscovered false keeps it off the dashboard).
 */
class SyncHealthOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $since = now()->subDays(7);
        $applied = SyncOp::where('status', 'applied')->where('created_at', '>=', $since)->count();
        $rejected = SyncOp::where('status', 'rejected')->where('created_at', '>=', $since)->count();
        $total = $applied + $rejected;
        $rate = $total > 0 ? round($rejected / $total * 100, 1) : 0;

        $tokens = PersonalAccessToken::query()->where('tokenable_type', (new User)->getMorphClass());
        $activeToday = (clone $tokens)->where('last_used_at', '>=', now()->subDay())->count();
        $stale = (clone $tokens)->where(fn ($query) => $query
            ->whereNull('last_used_at')
            ->orWhere('last_used_at', '<', now()->subDays(Devices::STALE_AFTER_DAYS)))->count();

        return [
            Stat::make('Operations synced (7 days)', number_format($applied))
                ->icon('heroicon-o-check-circle')
                ->color('success'),
            Stat::make('Rejected (7 days)', number_format($rejected))
                ->description($rate.'% of all operations')
                ->icon('heroicon-o-x-circle')
                ->color($rate > 5 ? 'danger' : 'warning'),
            Stat::make('Devices active today', number_format($activeToday))
                ->icon('heroicon-o-device-phone-mobile')
                ->color('info'),
            Stat::make('Stale devices', number_format($stale))
                ->description('Not seen for '.Devices::STALE_AFTER_DAYS.'+ days')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($stale > 0 ? 'warning' : 'success'),
        ];
    }
}
