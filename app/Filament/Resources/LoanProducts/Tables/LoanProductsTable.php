<?php

namespace App\Filament\Resources\LoanProducts\Tables;

use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LoanProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('interest_method')->badge(),
                TextColumn::make('interest_rate_bps')->label('Rate (bps)'),
                TextColumn::make('term_period_count')->label('Periods'),
                TextColumn::make('repayment_frequency')->badge(),
                TextColumn::make('min_amount')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('max_amount')->formatStateUsing(fn (int $state): string => Money::format($state)),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
