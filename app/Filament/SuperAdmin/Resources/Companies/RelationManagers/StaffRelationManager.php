<?php

namespace App\Filament\SuperAdmin\Resources\Companies\RelationManagers;

use App\Filament\SuperAdmin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use STS\FilamentImpersonate\Actions\Impersonate;

/**
 * The company's staff (customers excluded, as in UserResource). New company
 * admins are added through the page's "Add company admin" action; the rest
 * of the staff is the company admin's own job in the admin panel.
 */
class StaffRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Staff';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('name', 'customer'))
                ->with(['roles', 'branch', 'branches']))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('roles.name')->label('Role')->badge(),
                TextColumn::make('branch.name')->label('Primary branch')->placeholder('—'),
                TextColumn::make('branches_count')->counts('branches')->label('Branch access'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name', fn (Builder $query) => $query->whereIn('name', ['company_admin', 'branch_manager', 'field_agent'])),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                Impersonate::make()
                    ->redirectTo(function (User $record): string {
                        $branch = $record->branches->first();

                        return $branch ? (Filament::getPanel('admin')->getUrl($branch) ?? '/') : '/';
                    }),
                Action::make('edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (User $record): string => UserResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('name');
    }
}
