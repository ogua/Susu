<?php

namespace App\Actions\Savings;

use App\Enums\ClientOrigin;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\SavingsAccount;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;

/**
 * Matures a fixed-deposit account: credits principal-held interest
 * (rate * days-from-funding-to-maturity / 365, using the account's own snapshotted
 * interest_rate_bps, not the product's current one) into the account's
 * balance and flips matured_at, unlocking withdrawal. Idempotent — a
 * second call on an already-matured account is a no-op, safe against
 * concurrent scheduler runs (checked under a row lock, mirroring
 * PayWithdrawalAction's locking discipline since this posts a ledger entry
 * and mutates balance, unlike MatureTargetSavingsAccounts which only flips
 * a flag).
 */
class MatureFixedDepositAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
    ) {}

    public function execute(SavingsAccount $account): SavingsAccount
    {
        return DB::transaction(function () use ($account): SavingsAccount {
            /** @var SavingsAccount $account */
            $account = SavingsAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($account->matured_at !== null) {
                return $account;
            }

            // Interest runs from the day the principal was actually deposited
            // (the funding collection sets cycle_started_at), not from opening.
            $fundedOn = ($account->cycle_started_at ?? $account->opened_at)->copy()->startOfDay();
            $termDays = max(0, (int) $fundedOn->diffInDays($account->matures_at->copy()->startOfDay()));
            $rateBps = $account->interest_rate_bps ?? 0;
            $interest = intdiv($account->balance * $rateBps * $termDays, 10_000 * 365);

            if ($interest > 0) {
                $this->ledger->post(new EntryData(
                    company: $account->company,
                    type: TransactionType::SavingsInterest,
                    lines: [
                        ['account' => $this->chart->savingsInterestExpense($account->company), 'debit' => $interest],
                        ['account' => $account->ledgerAccount, 'credit' => $interest],
                    ],
                    branch: $account->branch,
                    paymentMethod: PaymentMethod::Internal,
                    origin: ClientOrigin::System,
                    description: "Fixed deposit maturity interest {$account->account_number}",
                    meta: [
                        'customer_id' => $account->customer_id,
                        'savings_account_id' => $account->id,
                        'term_days' => $termDays,
                        'rate_bps' => $rateBps,
                        'amount' => $interest,
                    ],
                ));
            }

            $account->forceFill([
                'balance' => $account->balance + $interest,
                'matured_at' => now(),
            ])->save();

            return $account;
        });
    }
}
