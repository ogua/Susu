<?php

namespace App\Filament\Resources\Staff;

use App\Filament\Resources\Staff\Pages\CreateStaff;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Filament\Resources\Staff\Schemas\StaffForm;
use App\Filament\Resources\Staff\Tables\StaffTable;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Branch/company-level staff management (company_admin, branch_manager,
 * field_agent) — distinct from SuperAdmin's UserResource, which manages
 * platform staff across every company. See App\Policies\UserPolicy for the
 * hierarchical who-can-manage-whom rules this resource enforces.
 */
class StaffResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'staff';

    protected static ?string $modelLabel = 'Staff Member';

    protected static ?string $pluralModelLabel = 'Staff';

    /**
     * Company admins oversee every branch of their company, so — mirroring
     * CustomerResource — they're exempted from Filament's automatic
     * per-branch tenant scope and instead scoped to the whole company.
     * branch_managers keep explicit branch scoping and are further limited
     * to field_agent staff (see UserPolicy::assignableRoles).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->whereKeyNot(auth()->id())
            ->whereDoesntHave('roles', fn (Builder $query) => $query->whereIn('name', ['super_admin', 'customer']));

        $user = auth()->user();

        if ($user?->hasRole('company_admin')) {
            return $query->where('company_id', $user->company_id);
        }

        return $query
            ->whereHas('branches', fn (Builder $query) => $query->whereKey(Filament::getTenant()?->id))
            ->whereHas('roles', fn (Builder $query) => $query->where('name', 'field_agent'));
    }

    public static function isScopedToTenant(): bool
    {
        return ! (auth()->user()?->hasRole('company_admin') ?? false);
    }

    public static function form(Schema $schema): Schema
    {
        return StaffForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StaffTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
            'create' => CreateStaff::route('/create'),
            'edit' => EditStaff::route('/{record}/edit'),
        ];
    }
}
