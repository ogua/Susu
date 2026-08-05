<?php

namespace App\Filament\Resources\GroupLoans;

use App\Filament\Resources\GroupLoans\Pages\CreateGroupLoan;
use App\Filament\Resources\GroupLoans\Pages\ListGroupLoans;
use App\Filament\Resources\GroupLoans\RelationManagers\BorrowersRelationManager;
use App\Filament\Resources\GroupLoans\RelationManagers\InstallmentsRelationManager;
use App\Filament\Resources\GroupLoans\Schemas\GroupLoanForm;
use App\Filament\Resources\GroupLoans\Tables\GroupLoansTable;
use App\Models\GroupLoan;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Applications originate here (or via API/sync); decisions happen via table actions, never edit. */
class GroupLoanResource extends Resource
{
    protected static ?string $model = GroupLoan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Loans';

    protected static ?string $recordTitleAttribute = 'loan_number';

    /** Company/super admins oversee every branch, so they get a company-wide query instead of the current tenant (see isScopedToTenant()). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->hasAnyRole(['company_admin', 'super_admin'])) {
            return $query->where('company_id', $user->company_id);
        }

        return $query->where('branch_id', Filament::getTenant()?->id);
    }

    public static function isScopedToTenant(): bool
    {
        return ! (auth()->user()?->hasAnyRole(['company_admin', 'super_admin']) ?? false);
    }

    public static function form(Schema $schema): Schema
    {
        return GroupLoanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GroupLoansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            BorrowersRelationManager::class,
            InstallmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroupLoans::route('/'),
            'create' => CreateGroupLoan::route('/create'),
        ];
    }
}
