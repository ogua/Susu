<?php

namespace App\Console\Commands;

use App\Models\LedgerAccount;
use App\Models\SavingsAccount;
use App\Services\Ledger\LedgerService;
use Illuminate\Console\Command;

/**
 * Two integrity checks: every ledger account's cached balance equals the sum
 * of its lines, and every savings account's cached balance equals its ledger
 * sub-account (they drift if domain state is changed without a matching
 * entry, e.g. a reversal that skipped ReverseJournalEntryAction).
 */
class VerifyLedgerBalances extends Command
{
    protected $signature = 'ledger:verify-balances';

    protected $description = 'Recompute every ledger account balance from its lines and check savings balances against the ledger';

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

        SavingsAccount::query()
            ->whereNotNull('ledger_account_id')
            ->with('ledgerAccount')
            ->chunkById(200, function ($accounts) use (&$drift): void {
                foreach ($accounts as $account) {
                    $ledgerBalance = $account->ledgerAccount?->balance;
                    if ($ledgerBalance !== null && $ledgerBalance !== $account->balance) {
                        $drift++;
                        $this->error(sprintf(
                            'Savings %s (%s): account balance %d, ledger %d',
                            $account->account_number, $account->id, $account->balance, $ledgerBalance,
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
