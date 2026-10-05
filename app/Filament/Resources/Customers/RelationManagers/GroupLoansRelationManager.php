<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Loans the customer took as a member of a customer group. */
class GroupLoansRelationManager extends RelationManager
{
    protected static string $relationship = 'groupLoans';

    protected static ?string $title = 'Group Loans';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('loan_number')
            ->columns([
                TextColumn::make('loan_number'),
                TextColumn::make('loanGroup.name')->label('Group'),
                TextColumn::make('principal_amount')->label('Principal')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('periodic_amount')->label('Per period')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('outstanding_balance')->label('Balance')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('status')->badge(),
                TextColumn::make('start_date')->date(),
            ])
            ->defaultSort('issued_at', 'desc');
    }
}
