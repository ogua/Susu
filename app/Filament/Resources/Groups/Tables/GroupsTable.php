<?php

namespace App\Filament\Resources\Groups\Tables;

use App\Actions\Groups\ActivateGroupAction;
use App\Models\Group;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('contribution_amount')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('frequency')->badge(),
                TextColumn::make('members_count')->label('Members')->counts('members'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'active' => 'Active',
                    'completed' => 'Completed',
                ]),
            ])
            ->recordActions([
                Action::make('activate')
                    ->color('success')
                    ->visible(fn (Group $record): bool => $record->status->value === 'draft')
                    ->authorize('activate')
                    ->requiresConfirmation()
                    ->modalDescription('This locks in the rotation and generates every round upfront. Members cannot be added afterward.')
                    ->action(function (Group $record): void {
                        app(ActivateGroupAction::class)->execute($record);
                        Notification::make()->title('Group activated')->success()->send();
                    }),
            ])
            ->defaultSort('name');
    }
}
