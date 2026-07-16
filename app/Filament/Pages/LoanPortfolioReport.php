<?php

namespace App\Filament\Pages;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** The branch loan book — on-page view of the loan portfolio report. */
class LoanPortfolioReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.report-table';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Loan Portfolio';

    protected static ?string $title = 'Loan Portfolio Report';

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole(['company_admin', 'branch_manager']) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->url(fn (): string => route('reports.loan-portfolio.pdf', [
                    'branch' => Filament::getTenant(),
                    ...$this->exportParams(),
                ]))
                ->openUrlInNewTab(),
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(fn (): string => route('reports.loan-portfolio.excel', [
                    'branch' => Filament::getTenant(),
                    ...$this->exportParams(),
                ]))
                ->openUrlInNewTab(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Loan::query()
                    ->where('branch_id', Filament::getTenant()?->id)
                    ->with(['customer', 'agent', 'loanProduct']),
            )
            ->columns([
                TextColumn::make('loan_number')->searchable(),
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->state(fn (Loan $record): string => $record->customer->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('agent.name')->label('Agent'),
                TextColumn::make('loanProduct.name')->label('Product'),
                TextColumn::make('status')->badge(),
                TextColumn::make('applied_at')->date()->sortable(),
                TextColumn::make('disbursed_at')->date()->sortable(),
                TextColumn::make('principal_amount')
                    ->label('Principal')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn ($state): string => Money::format((int) $state))),
                TextColumn::make('outstanding_balance')
                    ->label('Outstanding')
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state): string => Money::format((int) $state))
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn ($state): string => Money::format((int) $state))),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(LoanStatus::cases())
                        ->mapWithKeys(fn (LoanStatus $status): array => [
                            $status->value => ucwords(str_replace('_', ' ', $status->value)),
                        ])
                        ->all(),
                ),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until')->afterOrEqual('from'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query
                            ->where('applied_at', '>=', CarbonImmutable::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query
                            ->where('applied_at', '<=', CarbonImmutable::parse($date)->endOfDay()))),
            ])
            ->defaultSort('applied_at', 'desc');
    }

    /**
     * @return array<string, string>
     */
    private function exportParams(): array
    {
        $params = [];

        if ($from = data_get($this->tableFilters, 'period.from')) {
            $params['from'] = $from;
        }
        if ($until = data_get($this->tableFilters, 'period.until')) {
            $params['to'] = $until;
        }
        if ($status = data_get($this->tableFilters, 'status.value')) {
            $params['status'] = $status;
        }

        return $params;
    }
}
