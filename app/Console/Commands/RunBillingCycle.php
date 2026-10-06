<?php

namespace App\Console\Commands;

use App\Actions\Billing\RunBillingCycleAction;
use Illuminate\Console\Command;

/** Daily subscription billing: trials → invoices, renewals, past-due flags, non-payment suspensions. */
class RunBillingCycle extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Converts ended trials, renews subscription periods, flags overdue invoices and suspends companies for non-payment.';

    public function handle(RunBillingCycleAction $runBillingCycle): int
    {
        $summary = $runBillingCycle->execute();

        $this->info(sprintf(
            'Trials converted: %d · periods renewed: %d · newly past due: %d · suspended: %d',
            $summary['trials_converted'],
            $summary['renewed'],
            $summary['past_due'],
            $summary['suspended'],
        ));

        return self::SUCCESS;
    }
}
