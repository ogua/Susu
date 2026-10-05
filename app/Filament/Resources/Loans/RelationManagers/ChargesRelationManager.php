<?php

namespace App\Filament\Resources\Loans\RelationManagers;

use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: charges are fixed at application (their sum is the origination
 * fee deducted at disbursement), so editing them later would desync the books.
 */
class ChargesRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    protected static ?string $title = 'Charges';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('amount')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->summarize(Sum::make()->label('Total deducted at disbursement')->formatStateUsing(fn (?int $state): string => Money::format((int) $state))),
            ])
            ->emptyStateHeading('No charges on this loan');
    }
}
