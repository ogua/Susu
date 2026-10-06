<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Actions\Reports\BuildRevenueReportAction;
use App\Support\Money;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/** The platform's income by month (subscriptions + desktop licenses), MRR and receivables. */
class RevenueReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.super-admin.revenue-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Revenue';

    protected static ?string $title = 'Platform Revenue';

    /**
     * @return array{months: list<array<string, mixed>>, mrr: int, outstanding: int, overdue: int, paying_companies: int, trialing_companies: int}
     */
    public function report(): array
    {
        return app(BuildRevenueReportAction::class)->execute();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => collect($this->report()['months'])->reverse()->keyBy('month')->all())
            ->columns([
                TextColumn::make('label')->label('Month'),
                TextColumn::make('subscriptions')->label('Subscriptions')->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('licenses')->label('Desktop licenses')->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('total')->label('Total')->alignEnd()->weight('bold')->formatStateUsing(fn (int $state): string => Money::format($state)),
            ])
            ->paginated(false);
    }
}
