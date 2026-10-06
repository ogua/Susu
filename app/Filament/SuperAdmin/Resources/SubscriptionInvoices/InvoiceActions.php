<?php

namespace App\Filament\SuperAdmin\Resources\SubscriptionInvoices;

use App\Actions\Billing\RecordInvoicePaymentAction;
use App\Enums\InvoiceStatus;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Invoice actions shared by the Invoices resource and the company page's
 * Invoices tab.
 */
class InvoiceActions
{
    public static function recordPayment(): Action
    {
        return Action::make('recordPayment')
            ->label('Record payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (SubscriptionInvoice $record): bool => $record->status === InvoiceStatus::Unpaid)
            ->modalDescription('For payments received outside Paystack. Paying the last overdue invoice lifts a suspension for non-payment.')
            ->schema([
                Select::make('method')
                    ->options([
                        'bank_transfer' => 'Bank transfer',
                        'mobile_money' => 'Mobile money',
                        'cash' => 'Cash',
                        'cheque' => 'Cheque',
                        'waived' => 'Waived',
                    ])
                    ->required(),
                TextInput::make('reference')->label('Payment reference')->maxLength(191),
            ])
            ->action(function (array $data, SubscriptionInvoice $record): void {
                /** @var User|null $user */
                $user = Filament::auth()->user();
                app(RecordInvoicePaymentAction::class)->execute($record, $data['method'], $data['reference'] ?? null, $user);

                Notification::make()->title("Invoice {$record->number} marked paid")->success()->send();
            });
    }

    public static function void(): Action
    {
        return Action::make('void')
            ->label('Void')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (SubscriptionInvoice $record): bool => $record->status === InvoiceStatus::Unpaid)
            ->action(function (SubscriptionInvoice $record): void {
                app(RecordInvoicePaymentAction::class)->void($record);

                Notification::make()->title("Invoice {$record->number} voided")->success()->send();
            });
    }
}
