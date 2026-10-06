<?php

namespace App\Filament\SuperAdmin\Resources\DemoRequests\Tables;

use App\Enums\DemoOrganisationType;
use App\Models\DemoRequest;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DemoRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Received')->since()->sortable(),
                TextColumn::make('organisation')->searchable()->description(fn (DemoRequest $record): string => $record->name),
                TextColumn::make('organisation_type')->label('Type')->badge(),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('phone')->searchable()->copyable(),
                TextColumn::make('branches_count')->label('Branches')->placeholder('—'),
                TextColumn::make('handled_at')->label('Handled')->since()->placeholder('Open'),
            ])
            ->filters([
                TernaryFilter::make('handled')
                    ->placeholder('Open only')
                    ->trueLabel('Handled only')
                    ->falseLabel('All requests')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('handled_at'),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query->whereNull('handled_at'),
                    ),
                SelectFilter::make('organisation_type')->label('Type')->options(DemoOrganisationType::class),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('markHandled')
                    ->label('Mark handled')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (DemoRequest $record): bool => $record->handled_at === null)
                    ->action(function (DemoRequest $record): void {
                        $record->markHandled(auth()->user());

                        Notification::make()->title('Marked as handled')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
