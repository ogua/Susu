<?php

namespace App\Filament\SuperAdmin\Resources\Companies;

use App\Filament\SuperAdmin\Resources\Companies\Pages\CreateCompany;
use App\Filament\SuperAdmin\Resources\Companies\Pages\EditCompany;
use App\Filament\SuperAdmin\Resources\Companies\Pages\ListCompanies;
use App\Filament\SuperAdmin\Resources\Companies\Pages\ViewCompany;
use App\Filament\SuperAdmin\Resources\Companies\RelationManagers\BranchesRelationManager;
use App\Filament\SuperAdmin\Resources\Companies\RelationManagers\ExportsRelationManager;
use App\Filament\SuperAdmin\Resources\Companies\RelationManagers\InvoicesRelationManager;
use App\Filament\SuperAdmin\Resources\Companies\RelationManagers\StaffRelationManager;
use App\Filament\SuperAdmin\Resources\Companies\Schemas\CompanyForm;
use App\Filament\SuperAdmin\Resources\Companies\Schemas\CompanyInfolist;
use App\Filament\SuperAdmin\Resources\Companies\Tables\CompaniesTable;
use App\Models\Company;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Every tenant company on the platform — the SusuApp operator's own view,
 * not scoped to any tenant (this panel has no tenancy at all).
 */
class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return CompanyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CompanyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompaniesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            BranchesRelationManager::class,
            StaffRelationManager::class,
            InvoicesRelationManager::class,
            ExportsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'view' => ViewCompany::route('/{record}'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
