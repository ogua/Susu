<?php

namespace App\Filament\Resources\WithdrawalRequests\Tables;

use App\Actions\Savings\DecideWithdrawalAction;
use App\Actions\Savings\PayWithdrawalAction;
use App\Models\WithdrawalRequest;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WithdrawalRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('savingsAccount.account_number')->label('Account'),
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($record) => $record->customer->fullName()),
                TextColumn::make('amount')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('reason')->limit(40),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'paid' => 'Paid',
                ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->color('success')
                    ->visible(fn (WithdrawalRequest $record): bool => $record->status->value === 'pending')
                    ->authorize('approve')
                    ->requiresConfirmation()
                    ->action(function (WithdrawalRequest $record): void {
                        app(DecideWithdrawalAction::class)->approve(Filament::auth()->user(), $record);
                        Notification::make()->title('Withdrawal approved')->success()->send();
                    }),
                Action::make('reject')
                    ->color('danger')
                    ->visible(fn (WithdrawalRequest $record): bool => $record->status->value === 'pending')
                    ->authorize('reject')
                    ->schema([
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (array $data, WithdrawalRequest $record): void {
                        app(DecideWithdrawalAction::class)->reject(Filament::auth()->user(), $record, $data['reason']);
                        Notification::make()->title('Withdrawal rejected')->success()->send();
                    }),
                Action::make('pay')
                    ->color('primary')
                    ->visible(fn (WithdrawalRequest $record): bool => $record->status->value === 'approved')
                    ->authorize('pay')
                    ->requiresConfirmation()
                    ->action(function (WithdrawalRequest $record): void {
                        app(PayWithdrawalAction::class)->execute(Filament::auth()->user(), $record);
                        Notification::make()->title('Withdrawal paid')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
