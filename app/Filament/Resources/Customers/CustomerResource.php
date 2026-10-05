<?php

namespace App\Filament\Resources\Customers;

use App\Filament\RelationManagers\ActivityHistoryRelationManager;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\BeneficiariesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\FamilyMembersRelationManager;
use App\Filament\Resources\Customers\RelationManagers\GroupLoansRelationManager;
use App\Filament\Resources\Customers\RelationManagers\IdentificationsRelationManager;
use App\Filament\Resources\Customers\RelationManagers\LoansRelationManager;
use App\Filament\Resources\Customers\RelationManagers\SavingsAccountsRelationManager;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'customer_code';

    /**
     * Company admins and super admins oversee every branch, so they're
     * exempted from Filament's automatic per-branch tenant scope (see
     * isScopedToTenant() below) and instead scoped to the whole company.
     * Everyone else keeps explicit branch scoping rather than relying
     * solely on Filament's automatic tenant global scope — belt-and-
     * suspenders for a financial multi-tenant app where a scoping gap
     * means cross-branch data leakage.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->hasAnyRole(['company_admin', 'super_admin'])) {
            return $query->where('company_id', $user->company_id);
        }

        return $query->where('branch_id', Filament::getTenant()?->id);
    }

    /**
     * Disables Filament's automatic per-branch tenant global scope for
     * company/super admins so getEloquentQuery() above can apply its own
     * company-wide scope instead — otherwise the tenant scope would still
     * silently restrict them to the current branch underneath it.
     */
    public static function isScopedToTenant(): bool
    {
        return ! (auth()->user()?->hasAnyRole(['company_admin', 'super_admin']) ?? false);
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SavingsAccountsRelationManager::class,
            LoansRelationManager::class,
            GroupLoansRelationManager::class,
            IdentificationsRelationManager::class,
            BeneficiariesRelationManager::class,
            FamilyMembersRelationManager::class,
            ActivityHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
