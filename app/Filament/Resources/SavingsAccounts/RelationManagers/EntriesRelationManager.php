<?php

namespace App\Filament\Resources\SavingsAccounts\RelationManagers;

use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only: journal entries are append-only (AD-2) — no create/edit/delete here. */
class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Transactions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('payment_method')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('amount')
                    ->state(fn ($record) => Money::format((int) ($record->meta['amount'] ?? $record->amount()))),
                TextColumn::make('recorded_at')->dateTime()->sortable(),
            ])
            ->defaultSort('recorded_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
