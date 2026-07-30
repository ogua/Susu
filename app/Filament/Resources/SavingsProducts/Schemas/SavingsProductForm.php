<?php

namespace App\Filament\Resources\SavingsProducts\Schemas;

use App\Enums\CommissionType;
use App\Enums\SavingsProductType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SavingsProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                TextInput::make('code')->required()->maxLength(20),
                Select::make('type')
                    ->options(SavingsProductType::class)
                    ->default(SavingsProductType::DailySusu)
                    ->live()
                    ->required(),
                TextInput::make('contribution_amount')
                    ->label(fn (Get $get): string => match ($get('type')) {
                        SavingsProductType::FixedDeposit->value => 'Principal amount (GHS)',
                        default => 'Daily contribution (GHS)',
                    })
                    ->numeric()
                    ->required(fn (Get $get): bool => $get('type') !== SavingsProductType::Shares->value)
                    ->visible(fn (Get $get): bool => $get('type') !== SavingsProductType::Shares->value)
                    ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn (?float $state): int => (int) round(($state ?? 0) * 100)),
                TextInput::make('cycle_length_days')
                    ->numeric()
                    ->required()
                    ->default(31),
                Select::make('commission_type')
                    ->options(CommissionType::class)
                    ->default(CommissionType::None)
                    ->live()
                    ->required(),
                TextInput::make('commission_value')
                    ->numeric()
                    ->default(0)
                    ->visible(fn (Get $get): bool => ! in_array($get('commission_type'), [
                        CommissionType::FirstContributionPerCycle->value,
                        CommissionType::None->value,
                    ]))
                    ->helperText(fn (Get $get): string => match ($get('commission_type')) {
                        CommissionType::Percentage->value => 'Basis points of each deposit (100 = 1%)',
                        CommissionType::PercentageOfBalancePerCycle->value => "Basis points of the account's current balance, charged once per cycle started (100 = 1%)",
                        default => 'Flat amount in pesewas per cycle started',
                    }),
                TextInput::make('early_withdrawal_penalty_bps')
                    ->label('Early withdrawal penalty')
                    ->numeric()
                    ->default(0)
                    ->visible(fn (Get $get): bool => $get('type') === SavingsProductType::Target->value)
                    ->helperText('Basis points of the withdrawn amount (100 = 1%), charged before the target matures.'),
                TextInput::make('interest_rate_bps')
                    ->label('Annual interest rate')
                    ->numeric()
                    ->default(0)
                    ->visible(fn (Get $get): bool => $get('type') === SavingsProductType::FixedDeposit->value)
                    ->helperText('Basis points per annum (100 = 1%), prorated by term and paid into the balance at maturity.'),
                TextInput::make('par_value')
                    ->label('Par value per share (GHS)')
                    ->numeric()
                    ->required(fn (Get $get): bool => $get('type') === SavingsProductType::Shares->value)
                    ->visible(fn (Get $get): bool => $get('type') === SavingsProductType::Shares->value)
                    ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn (?float $state): ?int => $state === null ? null : (int) round($state * 100)),
                Toggle::make('is_active')->default(true),
            ]);
    }
}
