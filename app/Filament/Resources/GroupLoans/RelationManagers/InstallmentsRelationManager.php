<?php

namespace App\Filament\Resources\GroupLoans\RelationManagers;

use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only: the schedule is built once at activation and updated only by repayment / deposit-offset actions. */
class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'installments';

    protected static ?string $title = 'Repayment Schedule';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sequence')
            ->columns([
                TextColumn::make('sequence')->label('#'),
                TextColumn::make('due_date')->date(),
                TextColumn::make('amount_due')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->summarize(Sum::make()->formatStateUsing(fn (int $state): string => Money::format($state))),
                TextColumn::make('amount_paid')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->summarize(Sum::make()->formatStateUsing(fn (int $state): string => Money::format($state))),
                TextColumn::make('remaining')->state(fn ($record): string => Money::format($record->remaining())),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('sequence')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
