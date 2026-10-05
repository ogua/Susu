<?php

namespace App\Filament\Resources\JournalEntries\Tables;

use App\Actions\Ledger\ReverseJournalEntryAction;
use App\Enums\TransactionType;
use App\Models\JournalEntry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

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
                SelectFilter::make('type')->options(
                    collect(TransactionType::cases())
                        ->mapWithKeys(fn (TransactionType $type): array => [
                            $type->value => ucwords(str_replace('_', ' ', $type->value)),
                        ])
                        ->all(),
                ),
                Filter::make('recorded_between')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until')->afterOrEqual('from'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query
                            ->where('recorded_at', '>=', CarbonImmutable::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query
                            ->where('recorded_at', '<=', CarbonImmutable::parse($date)->endOfDay()))),
            ])
            ->recordActions([
                Action::make('reverse')
                    ->color('danger')
                    ->visible(fn (JournalEntry $record): bool => ReverseJournalEntryAction::supports($record))
                    ->authorize('reverse')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (array $data, JournalEntry $record): void {
                        try {
                            app(ReverseJournalEntryAction::class)->execute($record, Filament::auth()->user(), $data['reason']);
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Entry reversed')->success()->send();
                    }),
            ])
            ->defaultSort('recorded_at', 'desc');
    }
}
