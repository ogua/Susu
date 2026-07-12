<?php

namespace App\Filament\Resources\AgentDailySummaries\Tables;

use App\Actions\Agents\ReconcileAgentDayAction;
use App\Models\AgentDailySummary;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AgentDailySummariesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('agent.name')->label('Agent')->searchable(),
                TextColumn::make('summary_date')->date()->sortable(),
                TextColumn::make('collections_count')->label('Collections'),
                TextColumn::make('expected_cash')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('declared_cash')->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Money::format($state)),
                TextColumn::make('variance')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Money::format($state))
                    ->color(fn (?int $state): string => $state === null || $state === 0 ? 'gray' : ($state < 0 ? 'danger' : 'warning')),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'open' => 'Open',
                    'submitted' => 'Submitted',
                    'reconciled' => 'Reconciled',
                    'flagged' => 'Flagged',
                ]),
            ])
            ->recordActions([
                Action::make('reconcile')
                    ->color('success')
                    ->visible(fn (AgentDailySummary $record): bool => $record->status->value === 'submitted')
                    ->authorize('reconcile')
                    ->schema([
                        Checkbox::make('flag')->label('Flag for follow-up (variance unresolved)'),
                        Textarea::make('notes'),
                    ])
                    ->action(function (array $data, AgentDailySummary $record): void {
                        app(ReconcileAgentDayAction::class)->execute(
                            Filament::auth()->user(),
                            $record,
                            (bool) ($data['flag'] ?? false),
                            $data['notes'] ?? null,
                        );
                        Notification::make()->title('Day sheet reconciled')->success()->send();
                    }),
            ])
            ->defaultSort('summary_date', 'desc');
    }
}
