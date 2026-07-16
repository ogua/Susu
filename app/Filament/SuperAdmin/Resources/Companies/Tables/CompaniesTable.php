<?php

namespace App\Filament\SuperAdmin\Resources\Companies\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('domain_alias')->searchable()->placeholder('—'),
                TextColumn::make('contact_email')->searchable(),
                TextColumn::make('contact_phone'),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('branches_count')->counts('branches')->label('Branches'),
                TextColumn::make('users_count')->counts('users')->label('Users'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }
}
