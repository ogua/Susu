<?php

namespace App\Filament\SuperAdmin\Widgets;

use App\Enums\LicenseSaleStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DesktopLicenseSale;
use App\Models\User;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Platform-wide, unlike the tenant admin panel's DashboardOverview — this
 * panel has no tenancy, so nothing here is scoped to a branch/company.
 */
class PlatformOverview extends StatsOverviewWidget
{
    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        return [
            $this->companies(),
            $this->branches(),
            $this->platformStaff(),
            $this->licenseRevenueThisMonth(),
        ];
    }

    private function companies(): Stat
    {
        $total = Company::count();
        $active = Company::where('is_active', true)->count();

        return Stat::make('Companies', (string) $total)
            ->description($active.' active')
            ->icon('heroicon-o-building-office-2')
            ->color('success');
    }

    private function branches(): Stat
    {
        return Stat::make('Branches', (string) Branch::count())
            ->icon('heroicon-o-map-pin')
            ->color('info');
    }

    /** Excludes the customer role — same reasoning as UserResource's default query. */
    private function platformStaff(): Stat
    {
        $count = User::query()
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'customer'))
            ->count();

        return Stat::make('Platform Staff', (string) $count)
            ->icon('heroicon-o-users')
            ->color('info');
    }

    private function licenseRevenueThisMonth(): Stat
    {
        $sales = DesktopLicenseSale::query()
            ->whereIn('status', [LicenseSaleStatus::Paid, LicenseSaleStatus::Issued])
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        $revenue = (int) $sales->sum('amount');
        $keysIssued = DesktopLicenseSale::query()
            ->where('status', LicenseSaleStatus::Issued)
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->count();

        return Stat::make('License Revenue (This Month)', Money::format($revenue))
            ->description($keysIssued.' key(s) issued this month')
            ->icon('heroicon-o-banknotes')
            ->color('warning');
    }
}
