<?php

namespace App\Filament\Resources\SavingsProducts\Schemas;

use App\Enums\CommissionType;
use App\Enums\SavingsProductType;
use App\Models\SavingsProduct;
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
                    ->label(fn (Get $get): string => self::type($get) === SavingsProductType::FixedDeposit
                        ? 'Principal amount (GHS)'
                        : 'Daily contribution (GHS)')
                    ->numeric()
                    ->minValue(0.01)
                    ->required(fn (Get $get): bool => self::type($get) !== SavingsProductType::Shares)
                    ->visible(fn (Get $get): bool => self::type($get) !== SavingsProductType::Shares)
                    ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn (?float $state): int => (int) round(($state ?? 0) * 100)),
                TextInput::make('cycle_length_days')
                    ->label('Cycle length (contributions)')
                    ->numeric()
                    ->minValue(1)
                    ->required(fn (Get $get): bool => self::isCycleBased($get))
                    ->visible(fn (Get $get): bool => self::isCycleBased($get))
                    ->default(31),
                Select::make('commission_type')
                    ->options(CommissionType::class)
                    ->default(CommissionType::None)
                    ->live()
                    ->required(fn (Get $get): bool => self::isCycleBased($get))
                    ->visible(fn (Get $get): bool => self::isCycleBased($get)),
                TextInput::make('commission_value')
                    ->label(fn (Get $get): string => self::commissionType($get) === CommissionType::FlatPerCycle
                        ? 'Commission per cycle (GHS)'
                        : 'Commission rate (basis points)')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(fn (Get $get): bool => self::hasCommissionValue($get))
                    ->visible(fn (Get $get): bool => self::hasCommissionValue($get))
                    ->helperText(fn (Get $get): string => match (self::commissionType($get)) {
                        CommissionType::Percentage => 'Basis points of each deposit (100 = 1%)',
                        CommissionType::PercentageOfBalancePerCycle => "Basis points of the account's current balance, charged once per cycle started (100 = 1%)",
                        default => 'Flat amount charged once per cycle started',
                    })
                    // Flat commission is stored in pesewas but entered in GHS
                    // like every other money field; rates stay in basis points.
                    ->formatStateUsing(fn (?int $state, ?SavingsProduct $record): int|float|null => $state !== null && $record?->commission_type === CommissionType::FlatPerCycle
                        ? $state / 100
                        : $state)
                    ->dehydrateStateUsing(fn (int|float|string|null $state, Get $get): int => self::commissionType($get) === CommissionType::FlatPerCycle
                        ? (int) round(((float) $state) * 100)
                        : (int) $state),
                TextInput::make('early_withdrawal_penalty_bps')
                    ->label('Early withdrawal penalty (basis points)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(10_000)
                    ->default(0)
                    ->visible(fn (Get $get): bool => self::type($get) === SavingsProductType::Target)
                    ->helperText('Basis points of the withdrawn amount (100 = 1%), charged before the target matures. The target amount is set per account when it is opened.'),
                TextInput::make('interest_rate_bps')
                    ->label('Annual interest rate (basis points)')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->visible(fn (Get $get): bool => self::type($get) === SavingsProductType::FixedDeposit)
                    ->helperText('Basis points per annum (100 = 1%), prorated by term and paid into the balance at maturity. The account is funded once with its principal.'),
                TextInput::make('term_days')
                    ->label('Default term (days)')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(3650)
                    ->visible(fn (Get $get): bool => in_array(self::type($get), [SavingsProductType::Target, SavingsProductType::FixedDeposit], true))
                    ->helperText("Optional. Pre-fills each new account's maturity date this many days after opening; it can still be changed per account."),
                TextInput::make('par_value')
                    ->label('Par value per share (GHS)')
                    ->numeric()
                    ->minValue(0.01)
                    ->required(fn (Get $get): bool => self::type($get) === SavingsProductType::Shares)
                    ->visible(fn (Get $get): bool => self::type($get) === SavingsProductType::Shares)
                    ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn (?float $state): ?int => $state === null ? null : (int) round($state * 100)),
                Toggle::make('is_active')->default(true),
            ]);
    }

    /**
     * Select state is an enum instance once hydrated but a raw string after
     * the user picks an option, so both shapes must be accepted.
     */
    private static function type(Get $get): ?SavingsProductType
    {
        $value = $get('type');

        return $value instanceof SavingsProductType ? $value : SavingsProductType::tryFrom((string) $value);
    }

    private static function commissionType(Get $get): ?CommissionType
    {
        $value = $get('commission_type');

        return $value instanceof CommissionType ? $value : CommissionType::tryFrom((string) $value);
    }

    /** Only susu-style products are collected in cycles and can carry commission. */
    private static function isCycleBased(Get $get): bool
    {
        return in_array(self::type($get), [SavingsProductType::DailySusu, SavingsProductType::Target], true);
    }

    private static function hasCommissionValue(Get $get): bool
    {
        return self::isCycleBased($get) && in_array(self::commissionType($get), [
            CommissionType::Percentage,
            CommissionType::PercentageOfBalancePerCycle,
            CommissionType::FlatPerCycle,
        ], true);
    }
}
