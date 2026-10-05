<?php

namespace App\Filament\Resources\LoanGroups\Tables;

use App\Filament\Pages\CollectionSheet;
use App\Models\LoanGroup;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
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
                TextColumn::make('group_outstanding')
                    ->label('Group outstanding')
                    ->state(fn (LoanGroup $record): string => Money::format($record->outstandingBalance())),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('enterTransaction')
                    ->label('Enter transaction')
                    ->icon(Heroicon::Banknotes)
                    ->color('gray')
                    ->url(fn (LoanGroup $record): string => CollectionSheet::getUrl(['group' => $record->id])),
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
