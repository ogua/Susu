<?php

namespace App\Console\Commands;

use App\Actions\Savings\MatureFixedDepositAction;
use App\Enums\SavingsProductType;
use App\Models\NotificationLog;
use App\Models\SavingsAccount;
use App\Services\Sms\SmsService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Matures fixed-deposit accounts once their matures_at date has passed,
 * crediting principal + prorated interest into the account balance via
 * MatureFixedDepositAction and unlocking withdrawal. Idempotent via the
 * matured_at IS NULL guard, mirrors savings:mature-target-accounts.
 */
class MatureFixedDeposits extends Command
{
    protected $signature = 'savings:mature-fixed-deposits';

    protected $description = 'Matures fixed-deposit accounts once their maturity date has passed, crediting interest.';

    public function handle(MatureFixedDepositAction $matureFixedDeposit, SmsService $sms): int
    {
        $matured = 0;

        SavingsAccount::query()
            ->whereHas('product', fn ($query) => $query->where('type', SavingsProductType::FixedDeposit))
            ->whereNotNull('matures_at')
            ->whereNull('matured_at')
            ->where('matures_at', '<=', now()->toDateString())
            ->with(['customer', 'company'])
            ->chunkById(200, function ($accounts) use (&$matured, $matureFixedDeposit, $sms): void {
                foreach ($accounts as $account) {
                    $maturedAccount = $matureFixedDeposit->execute($account);
                    $maturedAccount->setRelation('customer', $account->customer);
                    $maturedAccount->setRelation('company', $account->company);
                    $matured++;

                    $this->notify($maturedAccount, $sms);
                }
            });

        $this->info("Matured {$matured} fixed deposit account(s).");

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

        $body = "Your fixed deposit {$account->account_number} has matured with interest. "
            .'New balance: '.Money::format($account->balance).'.';

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
