<?php

namespace App\Actions\Savings;

use App\Enums\AccountStatus;
use App\Enums\LoanStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a savings account. Accounts are never deleted (their ledger
 * sub-account and history must stay), and only an empty account with no
 * withdrawal in flight and no open loan tied to it may be closed.
 */
class CloseSavingsAccountAction
{
    public function execute(SavingsAccount $account, User $closedBy): SavingsAccount
    {
        return DB::transaction(function () use ($account): SavingsAccount {
            $locked = SavingsAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($reason = $this->blockingReason($locked)) {
                throw ValidationException::withMessages(['account' => $reason]);
            }

            $locked->forceFill([
                'status' => AccountStatus::Closed,
                'closed_at' => now(),
            ])->save();

            return $account->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function blockingReason(SavingsAccount $account): ?string
    {
        return match (true) {
            $account->status === AccountStatus::Closed => 'This account is already closed.',
            $account->balance !== 0 => 'Only an account with a zero balance can be closed. Pay out the remaining balance first.',
            $account->withdrawalRequests()
                ->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::Approved])
                ->exists() => 'This account has a withdrawal request in progress.',
            Loan::where('savings_account_id', $account->id)
                ->whereIn('status', [LoanStatus::Applied, LoanStatus::Approved, LoanStatus::Disbursed])
                ->exists() => 'An open loan is linked to this account.',
            default => null,
        };
    }
}
