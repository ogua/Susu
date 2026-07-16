<?php

namespace App\Filament\Pages;

use App\Enums\EntryStatus;
use App\Enums\TransactionType;
use App\Models\JournalEntry;
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

/** Every susu collection for the branch — on-page view of the collections report. */
class CollectionsReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.report-table';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Collections';

    protected static ?string $title = 'Collections Report';

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
                ->url(fn (): string => route('reports.collections.pdf', [
                    'branch' => Filament::getTenant(),
                    ...$this->exportDateParams(),
                ]))
                ->openUrlInNewTab(),
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(fn (): string => route('reports.collections.excel', [
                    'branch' => Filament::getTenant(),
                    ...$this->exportDateParams(),
                ]))
                ->openUrlInNewTab(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                JournalEntry::query()
                    ->where('branch_id', Filament::getTenant()?->id)
                    ->where('type', TransactionType::Collection)
                    ->whereIn('status', [EntryStatus::Completed, EntryStatus::Reversed])
                    ->withSum('lines as amount_sum', 'debit')
                    ->with('recordedBy'),
            )
            ->columns([
                TextColumn::make('recorded_at')->label('Date')->dateTime()->sortable(),
                TextColumn::make('reference')->searchable(),
                TextColumn::make('recordedBy.name')->label('Agent'),
                TextColumn::make('description')->wrap(),
                TextColumn::make('payment_method')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('amount_sum')
                    ->label('Amount')
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state): string => Money::format((int) $state)),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until')->afterOrEqual('from'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query
                            ->where('recorded_at', '>=', CarbonImmutable::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query
                            ->where('recorded_at', '<=', CarbonImmutable::parse($date)->endOfDay()))),
            ])
            ->defaultSort('recorded_at', 'desc');
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
