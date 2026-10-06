<?php

namespace App\Filament\Resources\SavingsAccounts\Schemas;

use App\Enums\AccountStatus;
use App\Enums\SavingsProductType;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SavingsAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->relationship('customer', 'first_name', fn ($query) => $query->where('branch_id', Filament::getTenant()?->id))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->fullName().' ('.$record->customer_code.')')
                    ->searchable(['first_name', 'last_name', 'customer_code'])
                    ->required()
                    ->disabledOn('edit'),
                Select::make('savings_product_id')
                    ->label('Product')
                    ->options(fn () => SavingsProduct::where('company_id', Filament::getTenant()?->company_id)
                        ->where('is_active', true)
                        ->pluck('name', 'id'))
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $product = $state !== null ? SavingsProduct::find($state) : null;
                        if ($product?->hasMaturity() && $product->term_days !== null) {
                            $set('matures_at', now()->addDays($product->term_days)->toDateString());
                        }
                    })
                    ->required()
                    ->disabledOn('edit'),
                Select::make('agent_id')
                    ->label('Assigned agent')
                    ->options(fn () => User::role('field_agent')
                        ->where('branch_id', Filament::getTenant()?->id)
                        ->pluck('name', 'id'))
                    // Reassigning goes through the customer's "Assign agent"
                    // action so every active account moves together.
                    ->disabledOn('edit'),
                TextInput::make('contribution_amount')
                    ->label(fn (Get $get): string => self::productType($get) === SavingsProductType::FixedDeposit
                        ? 'Principal amount (GHS)'
                        : 'Daily contribution (GHS)')
                    ->numeric()
                    ->minValue(0.01)
                    ->required(fn (Get $get): bool => self::productType($get) !== SavingsProductType::Shares)
                    ->visible(fn (Get $get): bool => self::productType($get) !== SavingsProductType::Shares)
                    ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn (?float $state): int => (int) round(($state ?? 0) * 100))
                    ->disabledOn('edit'),
                TextInput::make('target_amount')
                    ->label('Target amount (GHS)')
                    ->numeric()
                    ->required(fn (Get $get): bool => self::isTargetProduct($get))
                    ->visible(fn (Get $get): bool => self::isTargetProduct($get))
                    ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn (?float $state): ?int => $state === null ? null : (int) round($state * 100))
                    ->disabledOn('edit'),
                DatePicker::make('matures_at')
                    ->label('Maturity date')
                    ->required(fn (Get $get): bool => self::isTargetOrFixedDepositProduct($get))
                    ->visible(fn (Get $get): bool => self::isTargetOrFixedDepositProduct($get))
                    ->minDate(now()->addDay())
                    ->disabledOn('edit'),
                // Closing is the "Close account" action (it checks the balance
                // is zero); the form only toggles active/dormant.
                Select::make('status')
                    ->options(fn (?SavingsAccount $record): array => $record?->status === AccountStatus::Closed
                        ? [AccountStatus::Closed->value => 'Closed']
                        : [AccountStatus::Active->value => 'Active', AccountStatus::Dormant->value => 'Dormant'])
                    ->disabled(fn (?SavingsAccount $record): bool => $record?->status === AccountStatus::Closed)
                    ->required()
                    ->visibleOn('edit'),
            ]);
    }

    private static function productType(Get $get): ?SavingsProductType
    {
        $productId = $get('savings_product_id');

        return $productId !== null ? SavingsProduct::find($productId)?->type : null;
    }

    private static function isTargetProduct(Get $get): bool
    {
        return self::productType($get) === SavingsProductType::Target;
    }

    private static function isTargetOrFixedDepositProduct(Get $get): bool
    {
        return in_array(self::productType($get), [SavingsProductType::Target, SavingsProductType::FixedDeposit], true);
    }
}
