<?php

namespace App\Console\Commands;

use App\Models\LedgerAccount;
use App\Services\Ledger\LedgerService;
use Illuminate\Console\Command;

class VerifyLedgerBalances extends Command
{
    protected $signature = 'ledger:verify-balances';

    protected $description = 'Recompute every ledger account balance from its lines and report drift';

    public function handle(LedgerService $ledger): int
    {
        $drift = 0;

        LedgerAccount::query()->chunkById(200, function ($accounts) use ($ledger, &$drift): void {
            foreach ($accounts as $account) {
                $computed = $ledger->recomputeBalance($account);
                if ($computed !== $account->balance) {
                    $drift++;
                    $this->error(sprintf(
                        '%s (%s): cached %d, computed %d',
                        $account->code, $account->id, $account->balance, $computed,
                    ));
                }
            }
        });

        if ($drift > 0) {
            $this->error("{$drift} account(s) out of balance.");

            return self::FAILURE;
        }

        $this->info('All ledger balances verified.');

        return self::SUCCESS;
    }
}
