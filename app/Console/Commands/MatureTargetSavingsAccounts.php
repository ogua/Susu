<?php

namespace App\Console\Commands;

use App\Enums\SavingsProductType;
use App\Models\NotificationLog;
use App\Models\SavingsAccount;
use App\Services\Sms\SmsService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Flags target-savings accounts as matured once their matures_at date has
 * passed, waiving the early-withdrawal penalty from that point on. Idempotent
 * via the matured_at IS NULL guard — a re-run never re-matures or re-notifies
 * the same account (mirrors loans:flag-arrears' penalty_due = 0 guard).
 */
class MatureTargetSavingsAccounts extends Command
{
    protected $signature = 'savings:mature-target-accounts';

    protected $description = 'Flags target-savings accounts as matured once their maturity date has passed.';

    public function handle(SmsService $sms): int
    {
        $matured = 0;

        SavingsAccount::query()
            ->whereHas('product', fn ($query) => $query->where('type', SavingsProductType::Target))
            ->whereNotNull('matures_at')
            ->whereNull('matured_at')
            ->where('matures_at', '<=', now()->toDateString())
            ->with(['customer', 'company'])
            ->chunkById(200, function ($accounts) use (&$matured, $sms): void {
                foreach ($accounts as $account) {
                    $account->forceFill(['matured_at' => now()])->save();
                    $matured++;

                    $this->notify($account, $sms);
                }
            });

        $this->info("Matured {$matured} target savings account(s).");

        return self::SUCCESS;
    }

    private function notify(SavingsAccount $account, SmsService $sms): void
    {
        $customer = $account->customer;
        if ($customer === null || ! $customer->phone) {
            return;
        }

        $settings = $sms->settingsFor($account->company);
        if (! $settings->notifications_enabled) {
            return;
        }

        $body = "Your target savings account {$account->account_number} has matured. "
            .'You can now withdraw your full balance of '.Money::format($account->balance).' without penalty.';

        try {
            $log = NotificationLog::create([
                'company_id' => $account->company_id,
                'customer_id' => $customer->id,
                'channel' => 'sms',
                'recipient' => $customer->phone,
                'body' => $body,
                'status' => 'queued',
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        try {
            $providerReference = $sms->send($account->company, $customer->phone, $body);
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
}
