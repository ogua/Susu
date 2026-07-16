<?php

namespace App\Filament\Pages;

use App\Enums\EntryStatus;
use App\Filament\Resources\LedgerAccounts\LedgerAccountResource;
use App\Models\LedgerAccount;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * All-accounts general ledger summary (activity totals per account, company-
 * wide like the trial balance); each row drills into the AccountLedger page
 * for the detailed running-balance view.
 */
class GeneralLedgerReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.report-table';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'General Ledger';

    protected static ?string $title = 'General Ledger';

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
                ->url(fn (): string => route('reports.general-ledger.pdf', [
                    'branch' => Filament::getTenant(),
                    ...$this->exportDateParams(),
                ]))
                ->openUrlInNewTab(),
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(fn (): string => route('reports.general-ledger.excel', [
                    'branch' => Filament::getTenant(),
                    ...$this->exportDateParams(),
                ]))
                ->openUrlInNewTab(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->accountsQuery())
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('txn_count')->label('Transactions')->alignEnd(),
                TextColumn::make('debit_total')
                    ->label('Debits')
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state): string => Money::format((int) $state)),
                TextColumn::make('credit_total')
                    ->label('Credits')
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state): string => Money::format((int) $state)),
                TextColumn::make('net')
                    ->label('Net Movement')
                    ->alignEnd()
                    ->state(fn (LedgerAccount $record): string => Money::format(
                        $record->type->normalBalance() === 'debit'
                            ? (int) $record->debit_total - (int) $record->credit_total
                            : (int) $record->credit_total - (int) $record->debit_total,
                    )),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until')->afterOrEqual('from'),
                    ])
                    // The period narrows the aggregates, not the account list —
                    // handled inside accountsQuery() via the filter state.
                    ->query(fn (Builder $query): Builder => $query),
            ])
            ->recordActions([
                Action::make('viewLedger')
                    ->label('View Ledger')
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->url(fn (LedgerAccount $record): string => LedgerAccountResource::getUrl('ledger', ['record' => $record])),
            ])
            ->defaultSort('code')
            ->paginated([25, 50, 100]);
    }

    /**
     * @return Builder<LedgerAccount>
     */
    private function accountsQuery(): Builder
    {
        $from = data_get($this->tableFilters, 'period.from');
        $until = data_get($this->tableFilters, 'period.until');

        $inPeriod = function ($query) use ($from, $until): void {
            $query->whereHas('entry', function ($entry) use ($from, $until): void {
                $entry->whereIn('status', [EntryStatus::Completed, EntryStatus::Reversed])
                    ->when($from, fn ($query, $date) => $query->where('recorded_at', '>=', CarbonImmutable::parse($date)->startOfDay()))
                    ->when($until, fn ($query, $date) => $query->where('recorded_at', '<=', CarbonImmutable::parse($date)->endOfDay()));
            });
        };

        return LedgerAccount::query()
            ->where('company_id', Filament::getTenant()?->company_id)
            ->withCount(['lines as txn_count' => $inPeriod])
            ->withSum(['lines as debit_total' => $inPeriod], 'debit')
            ->withSum(['lines as credit_total' => $inPeriod], 'credit');
    }

    /**
     * @return array<string, string>
     */
    private function exportDateParams(): array
    {
        $params = [];

        if ($from = data_get($this->tableFilters, 'period.from')) {
            $params['from'] = $from;
        }
        if ($until = data_get($this->tableFilters, 'period.until')) {
            $params['to'] = $until;
        }

        return $params;
    }
}
