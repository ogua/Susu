<?php

namespace App\Filament\Resources\Groups;

use App\Filament\Resources\Groups\Pages\CreateGroup;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\Pages\ViewGroup;
use App\Filament\Resources\Groups\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Groups\RelationManagers\RoundsRelationManager;
use App\Filament\Resources\Groups\Schemas\GroupForm;
use App\Filament\Resources\Groups\Tables\GroupsTable;
use App\Models\Group;
use App\Support\Money;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Susu groups (ROSCA): members contribute per round, one member is paid out the pool each round. */
class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Susu Groups';

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
        return GroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GroupsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Group')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('code'),
                        TextEntry::make('contribution_amount')
                            ->label('Contribution per round')
                            ->formatStateUsing(fn (int $state): string => Money::format($state)),
                        TextEntry::make('frequency')->badge(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('members_count')->label('Members')->state(fn (Group $record): int => $record->members()->count()),
                        TextEntry::make('activated_at')->dateTime()->placeholder('Not activated'),
                        TextEntry::make('completed_at')->dateTime()->placeholder('Not completed'),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            RoundsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroups::route('/'),
            'create' => CreateGroup::route('/create'),
            'view' => ViewGroup::route('/{record}'),
            'edit' => EditGroup::route('/{record}/edit'),
        ];
    }
}
