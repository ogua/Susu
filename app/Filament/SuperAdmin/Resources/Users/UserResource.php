<?php

namespace App\Filament\SuperAdmin\Resources\Users;

use App\Filament\SuperAdmin\Resources\Users\Pages\CreateUser;
use App\Filament\SuperAdmin\Resources\Users\Pages\EditUser;
use App\Filament\SuperAdmin\Resources\Users\Pages\ListUsers;
use App\Filament\SuperAdmin\Resources\Users\Schemas\UserForm;
use App\Filament\SuperAdmin\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform staff across every company — deliberately excludes the
 * `customer` role, which could number in the thousands and is a
 * tenant/company concern, not a platform-operator one.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'customer'));
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
