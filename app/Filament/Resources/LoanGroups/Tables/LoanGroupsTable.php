<?php

namespace App\Filament\Resources\LoanGroups\Tables;

use App\Models\LoanGroup;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LoanGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('members_count')->label('Members')->counts('members'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                Action::make('toggleActive')
                    ->label(fn (LoanGroup $record): string => $record->is_active ? 'Deactivate' : 'Reactivate')
                    ->color(fn (LoanGroup $record): string => $record->is_active ? 'danger' : 'success')
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->action(function (LoanGroup $record): void {
                        $record->update(['is_active' => ! $record->is_active]);
                        Notification::make()->title($record->is_active ? 'Loan group reactivated' : 'Loan group deactivated')->success()->send();
                    }),
            ])
            ->defaultSort('name');
    }
}
