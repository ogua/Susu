<?php

namespace App\Filament\Resources\LedgerAccounts\Tables;

use App\Support\Money;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LedgerAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('balance')->formatStateUsing(fn (int $state): string => Money::format($state)),
                IconColumn::make('is_system')->boolean()->label('System'),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    'asset' => 'Asset',
                    'liability' => 'Liability',
                    'income' => 'Income',
                    'expense' => 'Expense',
                    'equity' => 'Equity',
                ]),
            ])
            ->defaultSort('code');
    }
}
