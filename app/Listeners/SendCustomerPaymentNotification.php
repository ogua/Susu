<?php

namespace App\Listeners;

use App\Enums\TransactionType;
use App\Events\JournalEntryPosted;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\NotificationLog;
use App\Services\Sms\SmsService;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Every customer-affecting money movement notifies the customer (AD-10).
 * Deduped per (journal_entry_id, channel) so sync replays and webhook/verify
 * races can never double-text; delayed out of the company's quiet hours.
 */
class SendCustomerPaymentNotification implements ShouldQueue
{
    /** Entry types that touch a customer's money. */
    private const CUSTOMER_TYPES = [
        TransactionType::Collection,
        TransactionType::Withdrawal,
        TransactionType::Reversal,
        TransactionType::Disbursement,
        TransactionType::Repayment,
    ];

    public function __construct(private SmsService $sms) {}

    /**
     * Laravel calls this on a reflection-only instance (constructor not run)
     * to decide queue delay before actually dispatching the job — so this
     * must not rely on constructor-injected properties.
     */
    public function withDelay(JournalEntryPosted $event): int
    {
        return app(SmsService::class)->quietHoursDelay($event->entry->company, now());
    }

    public function handle(JournalEntryPosted $event): void
    {
        $entry = $event->entry;

        if (! in_array($entry->type, self::CUSTOMER_TYPES, true)) {
            return;
        }

        $customerId = $entry->meta['customer_id'] ?? null;
        if ($customerId === null) {
            return;
        }

        $customer = Customer::find($customerId);
        if ($customer === null || ! $customer->phone) {
            return;
        }

        $settings = $this->sms->settingsFor($entry->company);
        if (! $settings->notifications_enabled) {
            return;
        }

        $body = $this->composeBody($entry, $customer);

        try {
            $log = NotificationLog::create([
                'company_id' => $entry->company_id,
                'customer_id' => $customer->id,
                'journal_entry_id' => $entry->id,
                'channel' => 'sms',
                'recipient' => $customer->phone,
                'body' => $body,
                'status' => 'queued',
            ]);
        } catch (UniqueConstraintViolationException) {
            return; // already notified for this entry
        }

        try {
            $providerReference = $this->sms->send($entry->company, $customer->phone, $body);
            $log->update([
                'status' => 'sent',
                'provider_reference' => $providerReference,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed']);
            report($e);
        }
    }

    private function composeBody(JournalEntry $entry, Customer $customer): string
    {
        $amount = $entry->meta['amount'] ?? $entry->amount();
        $formatted = Money::format((int) $amount);
        $balance = isset($entry->meta['balance_after'])
            ? ' New balance: '.Money::format((int) $entry->meta['balance_after']).'.'
            : '';

        return match ($entry->type) {
            TransactionType::Collection => "Deposit of {$formatted} received on your susu account. {$balance} Ref: {$entry->reference}",
            TransactionType::Withdrawal => "Withdrawal of {$formatted} paid from your susu account.{$balance} Ref: {$entry->reference}",
            TransactionType::Disbursement => "Your loan of {$formatted} has been disbursed. Ref: {$entry->reference}",
            TransactionType::Repayment => "Loan repayment of {$formatted} received. Ref: {$entry->reference}",
            default => "A correction of {$formatted} was applied to your susu account.{$balance} Ref: {$entry->reference}",
        };
    }
}
