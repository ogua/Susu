<?php

namespace App\Filament\Resources\Loans;

use App\Filament\RelationManagers\ActivityHistoryRelationManager;
use App\Filament\Resources\Loans\Pages\CreateLoan;
use App\Filament\Resources\Loans\Pages\ListLoans;
use App\Filament\Resources\Loans\Pages\ViewLoan;
use App\Filament\Resources\Loans\RelationManagers\ChargesRelationManager;
use App\Filament\Resources\Loans\RelationManagers\CollateralsRelationManager;
use App\Filament\Resources\Loans\RelationManagers\GuarantorsRelationManager;
use App\Filament\Resources\Loans\RelationManagers\InstallmentsRelationManager;
use App\Filament\Resources\Loans\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\Loans\Schemas\LoanForm;
use App\Filament\Resources\Loans\Schemas\LoanInfolist;
use App\Filament\Resources\Loans\Tables\LoansTable;
use App\Models\Loan;
use App\Support\AgentAssignment;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Applications originate here (or via API/sync); decisions happen via table actions, never edit. */
class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

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

        $query->where('branch_id', Filament::getTenant()?->id);

        // Field agents only see the customers they're responsible for.
        return AgentAssignment::restricts($user) ? AgentAssignment::scopeLoans($query, $user) : $query;
    }

    public static function isScopedToTenant(): bool
    {
        return ! (auth()->user()?->hasAnyRole(['company_admin', 'super_admin']) ?? false);
    }

    public static function form(Schema $schema): Schema
    {
        return LoanForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LoanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LoansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            InstallmentsRelationManager::class,
            TransactionsRelationManager::class,
            CollateralsRelationManager::class,
            GuarantorsRelationManager::class,
            ChargesRelationManager::class,
            ActivityHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoans::route('/'),
            'create' => CreateLoan::route('/create'),
            'view' => ViewLoan::route('/{record}'),
        ];
    }
}
