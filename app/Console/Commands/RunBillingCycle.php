<?php

namespace App\Console\Commands;

use App\Actions\Billing\RunBillingCycleAction;
use App\Actions\Platform\BuildPlatformDigestAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Daily subscription billing: trials → invoices, renewals, past-due flags, non-payment suspensions. */
class RunBillingCycle extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Converts ended trials, renews subscription periods, flags overdue invoices and suspends companies for non-payment.';

    public function handle(RunBillingCycleAction $runBillingCycle): int
    {
        $summary = $runBillingCycle->execute();
        Cache::forever(BuildPlatformDigestAction::BILLING_RUN_CACHE_KEY, [...$summary, 'at' => now()->toIso8601String()]);

        $this->info(sprintf(
            'Trials converted: %d · periods renewed: %d · newly past due: %d · suspended: %d · reminders: %d · overdue notices: %d',
            $summary['trials_converted'],
            $summary['renewed'],
            $summary['past_due'],
            $summary['suspended'],
            $summary['reminders'],
            $summary['overdue_notices'],
        ));

        return self::SUCCESS;
    }
}
