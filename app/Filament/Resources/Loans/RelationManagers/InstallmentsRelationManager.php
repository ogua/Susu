<?php

namespace App\Filament\Resources\Loans\RelationManagers;

use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only: the schedule is built once at disbursement and updated only by RecordLoanRepaymentAction. */
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
                TextColumn::make('principal_due')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('interest_due')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('penalty_due')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('amount_paid')->state(fn ($record) => Money::format($record->amountPaid())),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('sequence')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
