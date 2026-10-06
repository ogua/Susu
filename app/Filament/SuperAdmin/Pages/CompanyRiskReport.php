<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Actions\Reports\BuildCompanyRiskReportAction;
use App\Filament\SuperAdmin\Resources\Companies\CompanyResource;
use App\Support\Money;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Portfolio at risk and field-agent activity (last 30 days) for every
 * company, riskiest first.
 */
class CompanyRiskReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.report-table';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Risk & Agent Activity';

    protected static ?string $title = 'Loan Risk & Agent Activity';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => app(BuildCompanyRiskReportAction::class)->execute()->sortByDesc('par_percent')->all())
            ->columns([
                TextColumn::make('company')
                    ->url(fn (array $record): string => CompanyResource::getUrl('view', ['record' => $record['company_id']])),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('outstanding')->label('Loans outstanding')->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('at_risk')->label('At risk')->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('par_percent')->label('PAR')->alignEnd()->suffix('%')
                    ->color(fn (float $state): ?string => match (true) {
                        $state >= 10 => 'danger',
                        $state >= 5 => 'warning',
                        default => null,
                    }),
                TextColumn::make('agents')->label('Field agents')->alignEnd(),
                TextColumn::make('active_agents')->label('Collected (30d)')->alignEnd()
                    ->color(fn (array $record): ?string => $record['agents'] > 0 && $record['active_agents'] === 0 ? 'danger' : null),
                TextColumn::make('collections')->label('Collections (30d)')->alignEnd(),
                TextColumn::make('collections_amount')->label('Collected amount')->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('per_active_agent')->label('Per active agent')->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state)),
            ])
            ->paginated([25, 50, 100]);
    }
}
