<?php

namespace App\Filament\Resources\GroupLoans\RelationManagers;

use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: surfaces each member's equal share and remaining outstanding
 * share for individual accountability reporting — bookkeeping only, since the
 * group's shared outstanding_balance (not this) is what's legally owed under
 * joint & several liability.
 */
class BorrowersRelationManager extends RelationManager
{
    protected static string $relationship = 'borrowers';

    protected static ?string $title = 'Members & Shares';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($record) => $record->customer->fullName()),
                TextColumn::make('share_principal')->label('Share')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('share_outstanding')->label('Outstanding share')->formatStateUsing(fn (int $state): string => Money::format($state)),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
