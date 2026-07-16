<?php

namespace App\Filament\Resources\LedgerAccounts\Tables;

use App\Enums\LedgerAccountType;
use App\Filament\Resources\LedgerAccounts\LedgerAccountResource;
use App\Models\LedgerAccount;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class LedgerAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (LedgerAccountType $state): string => match ($state) {
                        LedgerAccountType::Asset => 'success',
                        LedgerAccountType::Liability => 'danger',
                        LedgerAccountType::Equity => 'warning',
                        LedgerAccountType::Income => 'info',
                        LedgerAccountType::Expense => 'gray',
                    }),
                TextColumn::make('branch.name')
                    ->label('Branch')
                    ->placeholder('Company-wide')
                    ->toggleable(),
                TextColumn::make('balance')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd()
                    ->summarize(
                        Sum::make()
                            ->label('Total')
                            ->formatStateUsing(fn ($state): string => Money::format((int) $state)),
                    ),
                IconColumn::make('is_system')->boolean()->label('System')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    'asset' => 'Asset',
                    'liability' => 'Liability',
                    'income' => 'Income',
                    'expense' => 'Expense',
                    'equity' => 'Equity',
                ]),
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->relationship(
                        'branch',
                        'name',
                        fn ($query) => $query->where('company_id', Filament::getTenant()?->company_id),
                    ),
                TernaryFilter::make('is_system')->label('System accounts'),
            ])
            ->recordActions([
                Action::make('viewLedger')
                    ->label('View Ledger')
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->url(fn (LedgerAccount $record): string => LedgerAccountResource::getUrl('ledger', ['record' => $record])),
            ])
            ->defaultGroup(
                Group::make('type')
                    ->getTitleFromRecordUsing(fn (LedgerAccount $record): string => ucfirst($record->type->value))
                    ->collapsible(),
            )
            ->defaultSort('code');
    }
}
