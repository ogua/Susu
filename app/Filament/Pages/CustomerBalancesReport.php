<?php

namespace App\Filament\Pages;

use App\Enums\AccountStatus;
use App\Models\SavingsAccount;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** As-at-now balances for every open savings account in the branch. */
class CustomerBalancesReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.report-table';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Customer Balances';

    protected static ?string $title = 'Customer Balances';

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
                ->url(fn (): string => route('reports.customer-balances.pdf', Filament::getTenant()))
                ->openUrlInNewTab(),
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(fn (): string => route('reports.customer-balances.excel', Filament::getTenant()))
                ->openUrlInNewTab(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SavingsAccount::query()
                    ->where('branch_id', Filament::getTenant()?->id)
                    ->whereIn('status', [AccountStatus::Active, AccountStatus::Dormant])
                    ->with(['customer', 'product', 'agent']),
            )
            ->columns([
                TextColumn::make('account_number')->searchable(),
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->state(fn (SavingsAccount $record): string => $record->customer->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('customer.phone')->label('Phone')->toggleable(),
                TextColumn::make('product.name')->label('Product'),
                TextColumn::make('agent.name')->label('Agent'),
                TextColumn::make('status')->badge(),
                TextColumn::make('balance')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn ($state): string => Money::format((int) $state))),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'dormant' => 'Dormant',
                ]),
                SelectFilter::make('agent_id')
                    ->label('Agent')
                    ->relationship(
                        'agent',
                        'name',
                        fn ($query) => $query->where('branch_id', Filament::getTenant()?->id),
                    ),
            ])
            ->defaultSort('account_number');
    }
}
