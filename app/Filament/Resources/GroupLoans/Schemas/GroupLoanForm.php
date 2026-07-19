<?php

namespace App\Filament\Resources\GroupLoans\Schemas;

use App\Models\LoanProduct;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/** Applications only — approve/reject/disburse/repay all happen via table actions, never edit. */
class GroupLoanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('loan_group_id')
                    ->label('Loan group')
                    ->relationship('loanGroup', 'name', fn ($query) => $query->where('branch_id', Filament::getTenant()?->id)->where('is_active', true))
                    ->searchable()
                    ->required(),
                Select::make('loan_product_id')
                    ->label('Loan product')
                    ->options(fn () => LoanProduct::where('company_id', Filament::getTenant()?->company_id)
                        ->where('is_active', true)
                        ->pluck('name', 'id'))
                    ->required(),
                TextInput::make('amount')
                    ->label('Requested amount (GHS)')
                    ->numeric()
                    ->required(),
                Textarea::make('notes')->columnSpanFull(),
            ]);
    }
}
