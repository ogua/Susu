<?php

namespace App\Filament\Resources\JournalEntries\RelationManagers;

use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account.code')->label('Account'),
                TextColumn::make('account.name'),
                TextColumn::make('debit')->formatStateUsing(fn (int $state): string => $state > 0 ? Money::format($state) : '—'),
                TextColumn::make('credit')->formatStateUsing(fn (int $state): string => $state > 0 ? Money::format($state) : '—'),
                TextColumn::make('memo'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
