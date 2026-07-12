<?php

namespace App\Filament\Resources\SavingsAccounts\Tables;

use App\Actions\Savings\RecordCollectionAction;
use App\Enums\ClientOrigin;
use App\Models\SavingsAccount;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
                TextColumn::make('balance')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('cycle_number')->label('Cycle'),
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
                EditAction::make(),
            ])
            ->defaultSort('account_number');
    }
}
