<?php

namespace App\Filament\Resources\SavingsAccounts\Schemas;

use App\Enums\AccountStatus;
use App\Enums\SavingsProductType;
use App\Models\SavingsProduct;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
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
                    ->required()
                    ->disabledOn('edit'),
                Select::make('agent_id')
                    ->label('Assigned agent')
                    ->options(fn () => User::role('field_agent')
                        ->where('branch_id', Filament::getTenant()?->id)
                        ->pluck('name', 'id')),
                TextInput::make('contribution_amount')
                    ->label('Daily contribution (GHS)')
                    ->numeric()
                    ->required()
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
                    ->required(fn (Get $get): bool => self::isTargetProduct($get))
                    ->visible(fn (Get $get): bool => self::isTargetProduct($get))
                    ->minDate(now()->addDay())
                    ->disabledOn('edit'),
                Select::make('status')
                    ->options(AccountStatus::class)
                    ->required()
                    ->visibleOn('edit'),
            ]);
    }

    private static function isTargetProduct(Get $get): bool
    {
        $productId = $get('savings_product_id');

        return $productId !== null
            && SavingsProduct::find($productId)?->type === SavingsProductType::Target;
    }
}
