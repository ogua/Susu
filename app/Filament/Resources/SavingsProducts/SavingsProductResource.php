<?php

namespace App\Filament\Resources\SavingsProducts;

use App\Filament\Resources\SavingsProducts\Pages\CreateSavingsProduct;
use App\Filament\Resources\SavingsProducts\Pages\EditSavingsProduct;
use App\Filament\Resources\SavingsProducts\Pages\ListSavingsProducts;
use App\Filament\Resources\SavingsProducts\Schemas\SavingsProductForm;
use App\Filament\Resources\SavingsProducts\Tables\SavingsProductsTable;
use App\Models\SavingsProduct;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Products are company-level (shared across all of a company's branches),
 * not branch-level like most tenant-scoped resources — so tenancy is
 * disabled here and scoping is done manually by company_id.
 */
class SavingsProductResource extends Resource
{
    protected static ?string $model = SavingsProduct::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Savings';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->company_id);
    }

    public static function form(Schema $schema): Schema
    {
        return SavingsProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SavingsProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSavingsProducts::route('/'),
            'create' => CreateSavingsProduct::route('/create'),
            'edit' => EditSavingsProduct::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
