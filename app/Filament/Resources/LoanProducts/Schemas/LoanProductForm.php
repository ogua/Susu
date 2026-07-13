<?php

namespace App\Filament\Resources\LoanProducts\Schemas;

use App\Enums\InterestMethod;
use App\Enums\LoanFrequency;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class LoanProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                TextInput::make('code')->required()->maxLength(20),
                Grid::make(2)->schema([
                    Select::make('interest_method')
                        ->options(InterestMethod::class)
                        ->default(InterestMethod::Flat)
                        ->required()
                        ->helperText('Flat: rate on the original principal every period. Reducing: rate on the declining balance.'),
                    Select::make('repayment_frequency')
                        ->options(LoanFrequency::class)
                        ->default(LoanFrequency::Monthly)
                        ->required(),
                    TextInput::make('interest_rate_bps')
                        ->label('Interest rate (basis points, per period)')
                        ->numeric()
                        ->required()
                        ->helperText('300 = 3% per period'),
                    TextInput::make('term_period_count')
                        ->label('Number of installments')
                        ->numeric()
                        ->required()
                        ->default(6),
                ]),
                Grid::make(2)->schema([
                    TextInput::make('min_amount')
                        ->label('Minimum amount (GHS)')
                        ->numeric()
                        ->required()
                        ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                        ->dehydrateStateUsing(fn (?float $state): int => (int) round(($state ?? 0) * 100)),
                    TextInput::make('max_amount')
                        ->label('Maximum amount (GHS)')
                        ->numeric()
                        ->required()
                        ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                        ->dehydrateStateUsing(fn (?float $state): int => (int) round(($state ?? 0) * 100)),
                    TextInput::make('origination_fee_amount')
                        ->label('Origination fee (GHS)')
                        ->numeric()
                        ->default(0)
                        ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                        ->dehydrateStateUsing(fn (?float $state): int => (int) round(($state ?? 0) * 100))
                        ->helperText('Deducted from the disbursed cash; the customer still repays the full principal + interest.'),
                    TextInput::make('penalty_rate_bps')
                        ->label('Late penalty (basis points of overdue installment)')
                        ->numeric()
                        ->default(0),
                ]),
                TextInput::make('grace_period_days')
                    ->numeric()
                    ->default(3)
                    ->helperText('Days after the due date before an installment is flagged overdue.'),
                Toggle::make('is_active')->default(true),
            ]);
    }
}
