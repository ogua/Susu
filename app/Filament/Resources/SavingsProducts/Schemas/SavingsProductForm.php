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
                    ->label('Daily contribution (GHS)')
                    ->numeric()
                    ->required()
                    ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn (?float $state): int => (int) round(($state ?? 0) * 100)),
                TextInput::make('cycle_length_days')
                    ->numeric()
                    ->required()
                    ->default(31),
                Select::make('commission_type')
                    ->options(CommissionType::class)
                    ->default(CommissionType::FirstContributionPerCycle)
                    ->live()
                    ->required(),
                TextInput::make('commission_value')
                    ->numeric()
                    ->default(0)
                    ->visible(fn (Get $get): bool => $get('commission_type') !== CommissionType::FirstContributionPerCycle->value)
                    ->helperText(fn (Get $get): string => $get('commission_type') === CommissionType::Percentage->value
                        ? 'Basis points (100 = 1%)'
                        : 'Flat amount in pesewas per cycle started'),
                TextInput::make('early_withdrawal_penalty_bps')
                    ->label('Early withdrawal penalty')
                    ->numeric()
                    ->default(0)
                    ->visible(fn (Get $get): bool => $get('type') === SavingsProductType::Target->value)
                    ->helperText('Basis points of the withdrawn amount (100 = 1%), charged before the target matures.'),
                Toggle::make('is_active')->default(true),
            ]);
    }
}
