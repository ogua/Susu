<?php

namespace App\Filament\SuperAdmin\Resources\Users\Tables;

use App\Filament\Resources\Staff\StaffActions;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use STS\FilamentImpersonate\Actions\Impersonate;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('company.name')->label('Company')->searchable()->placeholder('—'),
                TextColumn::make('branch.name')->label('Primary Branch')->placeholder('—'),
                TextColumn::make('roles.name')->label('Roles')->badge()->separator(','),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company')->relationship('company', 'name')->searchable(),
                SelectFilter::make('roles')->relationship('roles', 'name'),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                Impersonate::make()
                    ->redirectTo(function (User $record): string {
                        $branch = $record->branches->first();

                        return $branch ? (Filament::getPanel('admin')->getUrl($branch) ?? '/') : '/';
                    }),
                EditAction::make(),
                StaffActions::resetPassword(),
                StaffActions::resetTwoFactor(),
            ])
            ->defaultSort('name');
    }
}
