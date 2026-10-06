<?php

namespace App\Actions\Savings;

use App\Enums\SavingsProductType;
use App\Enums\TransactionType;
use App\Enums\WithdrawalStatus;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pays an approved withdrawal in cash from branch till:
 * Dr customer savings liability / Cr branch cash.
 */
class PayWithdrawalAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
    ) {}

    public function execute(User $paidBy, WithdrawalRequest $request): WithdrawalRequest
    {
        return DB::transaction(function () use ($paidBy, $request): WithdrawalRequest {
            // Re-read under lock: two concurrent "pay" clicks must not both post.
            $locked = WithdrawalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== WithdrawalStatus::Approved) {
                throw ValidationException::withMessages(['request' => 'Only approved requests can be paid.']);
            }
            $request->setRawAttributes($locked->getAttributes(), true);

            /** @var SavingsAccount $account */
            $account = SavingsAccount::whereKey($request->savings_account_id)->lockForUpdate()->firstOrFail();

            if ($request->amount > $account->balance) {
                throw ValidationException::withMessages(['amount' => 'Account balance is insufficient.']);
            }

            $balanceAfter = $account->balance - $request->amount;
            $sharesRedeemed = $account->product->type === SavingsProductType::Shares
                ? intdiv($request->amount, max(1, (int) $account->product->par_value))
                : 0;
            $netCash = $request->amount - $request->penalty_amount;

            $lines = [
                ['account' => $account->ledgerAccount, 'debit' => $request->amount],
                ['account' => $this->chart->branchCash($account->branch), 'credit' => $netCash],
            ];
            if ($request->penalty_amount > 0) {
                $lines[] = ['account' => $this->chart->earlyWithdrawalPenaltyIncome($account->company), 'credit' => $request->penalty_amount];
            }

            $entry = $this->ledger->post(new EntryData(
                company: $account->company,
                type: TransactionType::Withdrawal,
                lines: $lines,
                branch: $account->branch,
                recordedBy: $paidBy,
                description: "Withdrawal {$account->account_number}",
                meta: [
                    'customer_id' => $account->customer_id,
                    'savings_account_id' => $account->id,
                    'withdrawal_request_id' => $request->id,
                    'amount' => $netCash,
                    'balance_after' => $balanceAfter,
                    'shares' => $sharesRedeemed,
                ],
            ));

            $account->forceFill([
                'balance' => $balanceAfter,
                'share_count' => max(0, $account->share_count - $sharesRedeemed),
            ])->save();

            $request->update([
                'status' => WithdrawalStatus::Paid,
                'paid_entry_id' => $entry->id,
            ]);

            return $request;
        });
    }
}
