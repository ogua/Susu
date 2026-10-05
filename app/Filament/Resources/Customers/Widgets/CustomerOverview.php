<?php

namespace App\Filament\Resources\Customers\Widgets;

use App\Actions\Customers\BuildCustomerOverviewAction;
use App\Enums\CustomerSegment;
use App\Filament\Resources\Customers\CustomerResource;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Header of the customer list — the same numbers /api/v1/customers/overview serves. */
class CustomerOverview extends StatsOverviewWidget
{
    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $overview = app(BuildCustomerOverviewAction::class)->execute(CustomerResource::getEloquentQuery());
        $segments = $overview['segments'];

        return [
            Stat::make('Total customers', number_format($overview['total']))
                ->description($overview['new_this_month'].' registered this month')
                ->icon('heroicon-o-users')
                ->color('primary'),
            Stat::make('Active', number_format($segments[CustomerSegment::Active->value]))
                ->description($segments[CustomerSegment::Pending->value].' pending (no account yet)')
                ->icon('heroicon-o-check-badge')
                ->color('success'),
            Stat::make('With active loans', number_format($segments[CustomerSegment::WithActiveLoans->value]))
                ->description($segments[CustomerSegment::WithdrawalRequests->value].' with open withdrawal requests')
                ->icon('heroicon-o-banknotes')
                ->color('info'),
            Stat::make('Savings held', Money::format($overview['savings_balance']))
                ->description($segments[CustomerSegment::Unassigned->value].' active customers without an agent')
                ->icon('heroicon-o-building-library')
                ->color('warning'),
        ];
    }
}
