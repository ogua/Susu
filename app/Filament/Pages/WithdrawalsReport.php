<?php

namespace App\Filament\Pages;

use App\Enums\WithdrawalStatus;
use App\Models\WithdrawalRequest;
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

/** Every withdrawal request for the branch — on-page view of the withdrawals report. */
class WithdrawalsReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.report-table';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Withdrawals';

    protected static ?string $title = 'Withdrawals Report';

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
                ->url(fn (): string => route('reports.withdrawals.pdf', [
                    'branch' => Filament::getTenant(),
                    ...$this->exportParams(),
                ]))
                ->openUrlInNewTab(),
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(fn (): string => route('reports.withdrawals.excel', [
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
                WithdrawalRequest::query()
                    ->where('branch_id', Filament::getTenant()?->id)
                    ->with(['savingsAccount', 'customer', 'requestedBy', 'approvedBy']),
            )
            ->columns([
                TextColumn::make('created_at')->label('Requested')->dateTime()->sortable(),
                TextColumn::make('savingsAccount.account_number')->label('Account')->searchable(),
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->state(fn (WithdrawalRequest $record): ?string => $record->customer?->fullName()),
                TextColumn::make('status')->badge(),
                TextColumn::make('requestedBy.name')->label('Requested by')->toggleable(),
                TextColumn::make('approvedBy.name')->label('Approved by')->toggleable(),
                TextColumn::make('penalty_amount')
                    ->label('Penalty')
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state): string => Money::format((int) $state)),
                TextColumn::make('amount')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn ($state): string => Money::format((int) $state))),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(WithdrawalStatus::cases())
                        ->mapWithKeys(fn (WithdrawalStatus $status): array => [
                            $status->value => ucfirst($status->value),
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
                            ->where('created_at', '>=', CarbonImmutable::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query
                            ->where('created_at', '<=', CarbonImmutable::parse($date)->endOfDay()))),
            ])
            ->defaultSort('created_at', 'desc');
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
