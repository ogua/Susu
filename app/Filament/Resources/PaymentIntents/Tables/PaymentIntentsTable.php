<?php

namespace App\Filament\Resources\PaymentIntents\Tables;

use App\Actions\Payments\VerifyPaymentIntentAction;
use App\Models\PaymentIntent;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentIntentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider_reference')->label('Reference')->searchable(),
                TextColumn::make('payable.account_number')->label('Account'),
                TextColumn::make('flow')->badge(),
                TextColumn::make('channel')->badge(),
                TextColumn::make('phone'),
                TextColumn::make('amount')->state(fn (PaymentIntent $record) => Money::format($record->amount)),
                TextColumn::make('status')->badge(),
                TextColumn::make('initiatedBy.name')->label('Initiated by'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'initiated' => 'Initiated',
                    'pay_offline' => 'Pay offline',
                    'send_otp' => 'Send OTP',
                    'pending' => 'Pending',
                    'success' => 'Success',
                    'failed' => 'Failed',
                    'abandoned' => 'Abandoned',
                ]),
            ])
            ->recordActions([
                Action::make('verify')
                    ->label('Verify now')
                    ->visible(fn (PaymentIntent $record): bool => ! $record->status->isTerminal())
                    ->authorize('verify')
                    ->action(function (PaymentIntent $record): void {
                        $updated = app(VerifyPaymentIntentAction::class)->execute($record);
                        Notification::make()
                            ->title('Payment status: '.$updated->status->value)
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
