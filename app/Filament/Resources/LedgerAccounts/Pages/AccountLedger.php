<?php

namespace App\Filament\Resources\LedgerAccounts\Pages;

use App\Enums\EntryStatus;
use App\Enums\TransactionType;
use App\Filament\Resources\LedgerAccounts\LedgerAccountResource;
use App\Models\JournalLine;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * One ledger account's transaction history: every posted journal line with
 * debit/credit totals and a true point-in-time running balance (cumulative
 * from inception, not from the filtered window, so the balance column always
 * matches the books regardless of the date filter).
 */
class AccountLedger extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected string $view = 'filament.resources.ledger-accounts.pages.account-ledger';

    protected static ?string $title = 'Account Ledger';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getSubheading(): ?string
    {
        $account = $this->getRecord();

        return "{$account->code} — {$account->name}";
    }

    /** Balance carried into the filtered period; zero when no start date is set. */
    public function openingBalance(): int
    {
        $from = data_get($this->tableFilters, 'period.from');

        if (blank($from)) {
            return 0;
        }

        return $this->signedBalanceBefore(CarbonImmutable::parse($from));
    }

    public function currentBalance(): int
    {
        return (int) $this->getRecord()->balance;
    }

    public function hasPeriodStart(): bool
    {
        return filled(data_get($this->tableFilters, 'period.from'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->url(fn (): string => route('reports.general-ledger.pdf', [
                    'branch' => Filament::getTenant(),
                    'account_id' => $this->getRecord()->id,
                    ...$this->exportDateParams(),
                ]))
                ->openUrlInNewTab(),
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(fn (): string => route('reports.general-ledger.excel', [
                    'branch' => Filament::getTenant(),
                    'account_id' => $this->getRecord()->id,
                    ...$this->exportDateParams(),
                ]))
                ->openUrlInNewTab(),
            Action::make('print')
                ->label('Print')
                ->icon(Heroicon::OutlinedPrinter)
                ->alpineClickHandler('window.print()'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->linesQuery())
            ->columns([
                TextColumn::make('entry.recorded_at')->label('Date')->dateTime(),
                TextColumn::make('entry.reference')->label('Reference')->searchable(),
                TextColumn::make('entry.type')->label('Type')->badge(),
                TextColumn::make('memo')
                    ->label('Description')
                    ->state(fn (JournalLine $record): ?string => $record->memo ?? $record->entry->description)
                    ->wrap(),
                TextColumn::make('debit')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? Money::format($state) : '')
                    ->summarize(
                        Sum::make()
                            ->label('Total')
                            ->formatStateUsing(fn ($state): string => Money::format((int) $state)),
                    ),
                TextColumn::make('credit')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? Money::format($state) : '')
                    ->summarize(
                        Sum::make()
                            ->label('Total')
                            ->formatStateUsing(fn ($state): string => Money::format((int) $state)),
                    ),
                TextColumn::make('running_raw')
                    ->label('Balance')
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state): string => Money::format($this->signAmount((int) $state))),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until')->afterOrEqual('from'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query
                            ->where('journal_entries.recorded_at', '>=', CarbonImmutable::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query
                            ->where('journal_entries.recorded_at', '<=', CarbonImmutable::parse($date)->endOfDay()))),
                SelectFilter::make('type')
                    ->options(collect(TransactionType::cases())
                        ->mapWithKeys(fn (TransactionType $type): array => [$type->value => ucwords(str_replace('_', ' ', $type->value))])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $query, string $type): Builder => $query
                            ->where('journal_entries.type', $type))),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getResource(): string
    {
        return LedgerAccountResource::class;
    }

    /**
     * @return Builder<JournalLine>
     */
    private function linesQuery(): Builder
    {
        // Running balance per row via a correlated subquery so it stays
        // correct across pagination (a PHP accumulator would drift on page 2+).
        // UUIDv7 ids order by creation time, making them a safe tie-breaker.
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_account_id', $this->getRecord()->id)
            ->whereIn('journal_entries.status', [EntryStatus::Completed->value, EntryStatus::Reversed->value])
            ->select('journal_lines.*')
            ->selectRaw(<<<'SQL'
                (
                    select coalesce(sum(l2.debit - l2.credit), 0)
                    from journal_lines l2
                    inner join journal_entries e2 on e2.id = l2.journal_entry_id
                    where l2.ledger_account_id = journal_lines.ledger_account_id
                      and e2.status in ('completed', 'reversed')
                      and (
                        e2.recorded_at < journal_entries.recorded_at
                        or (e2.recorded_at = journal_entries.recorded_at and l2.id <= journal_lines.id)
                      )
                ) as running_raw
                SQL)
            ->with('entry.recordedBy')
            ->orderBy('journal_entries.recorded_at')
            ->orderBy('journal_lines.id');
    }

    /** Debit-normal accounts read Dr−Cr as positive; credit-normal the reverse. */
    private function signAmount(int $debitMinusCredit): int
    {
        return $this->getRecord()->type->normalBalance() === 'debit'
            ? $debitMinusCredit
            : -$debitMinusCredit;
    }

    private function signedBalanceBefore(CarbonImmutable $date): int
    {
        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_account_id', $this->getRecord()->id)
            ->whereIn('journal_entries.status', [EntryStatus::Completed->value, EntryStatus::Reversed->value])
            ->where('journal_entries.recorded_at', '<', $date->startOfDay())
            ->selectRaw('coalesce(sum(journal_lines.debit),0) as debits, coalesce(sum(journal_lines.credit),0) as credits')
            ->first();

        return $this->signAmount((int) $sums->debits - (int) $sums->credits);
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
