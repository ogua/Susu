<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The customer's individual loans; each row opens the loan's own page. */
class LoansRelationManager extends RelationManager
{
    protected static string $relationship = 'loans';

    protected static ?string $title = 'Loans';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('loan_number')
            ->columns([
                TextColumn::make('loan_number'),
                TextColumn::make('loanProduct.name')->label('Product'),
                TextColumn::make('principal_amount')->label('Principal')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('outstanding_balance')->label('Balance')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('status')->badge(),
                TextColumn::make('disbursed_at')->label('Disbursed')->date()->placeholder('—'),
            ])
            ->defaultSort('applied_at', 'desc');
    }
}
