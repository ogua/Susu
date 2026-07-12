<?php

namespace App\Filament\Resources\SavingsAccounts;

use App\Filament\Resources\SavingsAccounts\Pages\CreateSavingsAccount;
use App\Filament\Resources\SavingsAccounts\Pages\EditSavingsAccount;
use App\Filament\Resources\SavingsAccounts\Pages\ListSavingsAccounts;
use App\Filament\Resources\SavingsAccounts\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\SavingsAccounts\Schemas\SavingsAccountForm;
use App\Filament\Resources\SavingsAccounts\Tables\SavingsAccountsTable;
use App\Models\SavingsAccount;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SavingsAccountResource extends Resource
{
    protected static ?string $model = SavingsAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'account_number';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('branch_id', Filament::getTenant()?->id);
    }

    public static function form(Schema $schema): Schema
    {
        return SavingsAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SavingsAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSavingsAccounts::route('/'),
            'create' => CreateSavingsAccount::route('/create'),
            'edit' => EditSavingsAccount::route('/{record}/edit'),
        ];
    }
}
