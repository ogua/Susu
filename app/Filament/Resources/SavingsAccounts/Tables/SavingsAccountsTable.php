<?php

namespace App\Filament\Resources\SavingsAccounts\Tables;

use App\Actions\Savings\BuySharesAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\ClientOrigin;
use App\Enums\SavingsProductType;
use App\Models\SavingsAccount;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SavingsAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account_number')->searchable(),
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($record) => $record->customer->fullName())
                    ->searchable(['customer.first_name', 'customer.last_name']),
                TextColumn::make('agent.name')->label('Agent'),
                TextColumn::make('product.type')->label('Type')->badge(),
                TextColumn::make('balance')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('cycle_number')->label('Cycle'),
                TextColumn::make('target_progress')
                    ->label('Progress')
                    ->state(fn (SavingsAccount $record): ?string => $record->targetProgressPercent() !== null
                        ? $record->targetProgressPercent().'% of '.Money::format($record->target_amount)
                        : null)
                    ->placeholder('—'),
                TextColumn::make('matures_at')->label('Matures')->date()->placeholder('—'),
                TextColumn::make('share_count')
                    ->label('Shares')
                    ->state(fn (SavingsAccount $record): ?int => $record->product->type === SavingsProductType::Shares ? $record->share_count : null)
                    ->placeholder('—'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'dormant' => 'Dormant',
                    'closed' => 'Closed',
                ]),
            ])
            ->recordActions([
                Action::make('recordCollection')
                    ->label('Record Collection')
                    ->visible(fn (SavingsAccount $record): bool => $record->product->type !== SavingsProductType::Shares)
                    ->schema([
                        TextInput::make('amount')
                            ->label('Amount (GHS)')
                            ->numeric()
                            ->required(),
                    ])
                    ->action(function (array $data, SavingsAccount $record): void {
                        app(RecordCollectionAction::class)->execute(
                            agent: Filament::auth()->user(),
                            account: $record,
                            amount: Money::toMinorUnits($data['amount']),
                            origin: ClientOrigin::Web,
                        );

                        Notification::make()->title('Collection recorded')->success()->send();
                    }),
                Action::make('buyShares')
                    ->label('Buy Shares')
                    ->visible(fn (SavingsAccount $record): bool => $record->product->type === SavingsProductType::Shares)
                    ->schema([
                        TextInput::make('shares')
                            ->numeric()
                            ->required()
                            ->minValue(1),
                    ])
                    ->action(function (array $data, SavingsAccount $record): void {
                        app(BuySharesAction::class)->execute(
                            agent: Filament::auth()->user(),
                            account: $record,
                            shares: (int) $data['shares'],
                            origin: ClientOrigin::Web,
                        );

                        Notification::make()->title('Shares purchased')->success()->send();
                    }),
                Action::make('statement')
                    ->label('Statement')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->url(fn (SavingsAccount $record): string => route('savings-accounts.statement', $record))
                    ->openUrlInNewTab(),
                EditAction::make(),
            ])
            ->defaultSort('account_number');
    }
}
