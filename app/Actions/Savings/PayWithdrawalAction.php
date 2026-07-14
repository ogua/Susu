<?php

namespace App\Actions\Savings;

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
        if ($request->status !== WithdrawalStatus::Approved) {
            throw ValidationException::withMessages(['request' => 'Only approved requests can be paid.']);
        }

        return DB::transaction(function () use ($paidBy, $request): WithdrawalRequest {
            /** @var SavingsAccount $account */
            $account = SavingsAccount::whereKey($request->savings_account_id)->lockForUpdate()->firstOrFail();

            if ($request->amount > $account->balance) {
                throw ValidationException::withMessages(['amount' => 'Account balance is insufficient.']);
            }

            $balanceAfter = $account->balance - $request->amount;
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
                ],
            ));

            $account->forceFill(['balance' => $balanceAfter])->save();

            $request->update([
                'status' => WithdrawalStatus::Paid,
                'paid_entry_id' => $entry->id,
            ]);

            return $request;
        });
    }
}
