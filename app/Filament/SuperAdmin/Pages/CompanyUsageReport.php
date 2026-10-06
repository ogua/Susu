<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Actions\Reports\BuildCompanyUsageReportAction;
use App\Filament\SuperAdmin\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every tenant's size, book and activity side by side. The period filter
 * drives the period columns (new customers, collections) — it doesn't hide
 * companies — so the aggregates are rebuilt from it on each render.
 */
class CompanyUsageReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.report-table';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Company Usage';

    protected static ?string $title = 'Company Usage Report';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->url(fn (): string => route('platform-reports.company-usage.pdf', $this->exportDateParams()))
                ->openUrlInNewTab(),
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(fn (): string => route('platform-reports.company-usage.excel', $this->exportDateParams()))
                ->openUrlInNewTab(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(BuildCompanyUsageReportAction::class)->query($this->periodFrom(), $this->periodTo()))
            ->columns([
                TextColumn::make('name')->label('Company')->searchable()->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('branches_count')->label('Branches')->sortable()->alignEnd(),
                TextColumn::make('staff_count')->label('Staff')->sortable()->alignEnd(),
                TextColumn::make('company_admins_count')->label('Admins')->sortable()->alignEnd()
                    ->color(fn (int $state): ?string => $state === 0 ? 'danger' : null),
                TextColumn::make('customers_count')->label('Customers')->sortable()->alignEnd()
                    ->summarize(Sum::make()->label('')),
                TextColumn::make('new_customers_count')->label('New customers')->sortable()->alignEnd()
                    ->summarize(Sum::make()->label('')),
                TextColumn::make('active_savings_accounts_count')->label('Active accounts')->sortable()->alignEnd()
                    ->toggleable(),
                TextColumn::make('savings_balance')->label('Savings held')->sortable()->alignEnd()
                    ->formatStateUsing(fn ($state): string => Money::format((int) $state))
                    ->summarize(Sum::make()->label('')->formatStateUsing(fn ($state): string => Money::format((int) $state))),
                TextColumn::make('loans_outstanding')->label('Loans outstanding')->sortable()->alignEnd()
                    ->formatStateUsing(fn ($state): string => Money::format((int) $state))
                    ->summarize(Sum::make()->label('')->formatStateUsing(fn ($state): string => Money::format((int) $state))),
                TextColumn::make('collections_count')->label('Collections')->sortable()->alignEnd()
                    ->toggleable(),
                TextColumn::make('collections_amount')->label('Collected')->sortable()->alignEnd()
                    ->formatStateUsing(fn ($state): string => Money::format((int) $state))
                    ->summarize(Sum::make()->label('')->formatStateUsing(fn ($state): string => Money::format((int) $state))),
                TextColumn::make('last_activity_at')->label('Last transaction')->dateTime()->sortable()->placeholder('Never'),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->default(now()->startOfMonth()),
                        DatePicker::make('until')->default(now())->afterOrEqual('from'),
                    ])
                    ->query(fn (Builder $query): Builder => $query),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordUrl(fn (Company $record): string => CompanyResource::getUrl('view', ['record' => $record]))
            ->defaultSort('name');
    }

    private function periodFrom(): CarbonImmutable
    {
        $from = data_get($this->tableFilters, 'period.from');

        return $from ? CarbonImmutable::parse($from) : CarbonImmutable::now()->startOfMonth();
    }

    private function periodTo(): CarbonImmutable
    {
        $until = data_get($this->tableFilters, 'period.until');

        return $until ? CarbonImmutable::parse($until) : CarbonImmutable::now();
    }

    /**
     * @return array<string, string>
     */
    private function exportDateParams(): array
    {
        return [
            'from' => $this->periodFrom()->toDateString(),
            'to' => $this->periodTo()->toDateString(),
        ];
    }
}
