<?php

namespace App\Filament\Resources\SavingsProducts\Tables;

use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SavingsProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('contribution_amount')
                    ->label('Daily contribution')
                    ->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('cycle_length_days')->label('Cycle (days)'),
                TextColumn::make('commission_type')->badge(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
