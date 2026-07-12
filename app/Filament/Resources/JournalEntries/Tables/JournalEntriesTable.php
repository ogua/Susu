<?php

namespace App\Filament\Resources\JournalEntries\Tables;

use App\Models\JournalEntry;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class JournalEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('payment_method')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('amount')
                    ->state(fn (JournalEntry $record) => Money::format((int) ($record->meta['amount'] ?? $record->amount()))),
                TextColumn::make('recordedBy.name')->label('Recorded by'),
                TextColumn::make('recorded_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    'collection' => 'Collection',
                    'commission' => 'Commission',
                    'withdrawal' => 'Withdrawal',
                    'remittance' => 'Remittance',
                    'reversal' => 'Reversal',
                    'adjustment' => 'Adjustment',
                ]),
            ])
            ->recordActions([
                Action::make('reverse')
                    ->color('danger')
                    ->visible(fn (JournalEntry $record): bool => $record->status->value !== 'reversed')
                    ->authorize('reverse')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (array $data, JournalEntry $record): void {
                        app(LedgerService::class)->reverse($record, Filament::auth()->user(), $data['reason']);
                        Notification::make()->title('Entry reversed')->success()->send();
                    }),
            ])
            ->defaultSort('recorded_at', 'desc');
    }
}
