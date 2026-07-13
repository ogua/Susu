<?php

namespace App\Filament\Resources\Loans\Schemas;

use App\Models\LoanProduct;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/** Applications only — approve/reject/disburse/repay all happen via table actions, never edit. */
class LoanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->relationship('customer', 'first_name', fn ($query) => $query->where('branch_id', Filament::getTenant()?->id))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->fullName().' ('.$record->customer_code.')')
                    ->searchable(['first_name', 'last_name', 'customer_code'])
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
                TextInput::make('guarantor_name')->maxLength(150),
                TextInput::make('guarantor_phone')->maxLength(32),
                Textarea::make('notes')->columnSpanFull(),
            ]);
    }
}
