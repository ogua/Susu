<?php

namespace App\Filament\Resources\LoanGroups;

use App\Filament\Resources\LoanGroups\Pages\CreateLoanGroup;
use App\Filament\Resources\LoanGroups\Pages\ListLoanGroups;
use App\Filament\Resources\LoanGroups\RelationManagers\MembersRelationManager;
use App\Filament\Resources\LoanGroups\Schemas\LoanGroupForm;
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

/** A persistent roster of customers that can take out group loans repeatedly over time. */
class LoanGroupResource extends Resource
{
    protected static ?string $model = LoanGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Loans';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('branch_id', Filament::getTenant()?->id);
    }

    public static function form(Schema $schema): Schema
    {
        return LoanGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LoanGroupsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoanGroups::route('/'),
            'create' => CreateLoanGroup::route('/create'),
        ];
    }
}
