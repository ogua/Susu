<?php

namespace App\Filament\Resources\Customers\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer_code')->searchable()->label('Code'),
                TextColumn::make('first_name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->formatStateUsing(fn ($record) => $record->fullName()),
                TextColumn::make('phone')->searchable(),
                TextColumn::make('branch.name')->label('Branch')->badge()->searchable(),
                IconColumn::make('user_id')->label('Has login')->boolean(),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'dormant' => 'Dormant',
                    'closed' => 'Closed',
                ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
