<?php

namespace App\Filament\Resources\LoanGroups;

use App\Filament\Resources\LoanGroups\Pages\CreateLoanGroup;
use App\Filament\Resources\LoanGroups\Pages\EditLoanGroup;
use App\Filament\Resources\LoanGroups\Pages\ListLoanGroups;
use App\Filament\Resources\LoanGroups\Pages\ViewLoanGroup;
use App\Filament\Resources\LoanGroups\RelationManagers\MemberLoansRelationManager;
use App\Filament\Resources\LoanGroups\RelationManagers\MembersRelationManager;
use App\Filament\Resources\LoanGroups\Schemas\LoanGroupForm;
use App\Filament\Resources\LoanGroups\Schemas\LoanGroupInfolist;
use App\Filament\Resources\LoanGroups\Tables\LoanGroupsTable;
use App\Models\LoanGroup;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * "Customer Groups": a persistent branch roster of customers. Loans can be
 * issued to the whole group, savings opened for every member, and the group's
 * collection sheet records everyone's repayments and deposits at once.
 */
class LoanGroupResource extends Resource
{
    protected static ?string $model = LoanGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Customer Groups';

    protected static ?string $modelLabel = 'customer group';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

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
        return LoanGroupForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LoanGroupInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LoanGroupsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            MemberLoansRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoanGroups::route('/'),
            'create' => CreateLoanGroup::route('/create'),
            'view' => ViewLoanGroup::route('/{record}'),
            'edit' => EditLoanGroup::route('/{record}/edit'),
        ];
    }
}
