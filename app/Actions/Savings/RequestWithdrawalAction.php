<?php

namespace App\Actions\Savings;

use App\Enums\AccountStatus;
use App\Enums\SavingsProductType;
use App\Enums\WithdrawalStatus;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestWithdrawalAction
{
    public function execute(
        User $requestedBy,
        SavingsAccount $account,
        int $amount,
        ?string $reason = null,
        ?string $clientReference = null,
    ): WithdrawalRequest {
        if ($clientReference !== null) {
            $existing = WithdrawalRequest::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                if ($existing->savings_account_id !== $account->id) {
                    throw ValidationException::withMessages([
                        'client_reference' => 'This reference was already used for a different withdrawal.',
                    ]);
                }

                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($requestedBy, $account, $amount, $reason, $clientReference): WithdrawalRequest {
                // Lock the account so two concurrent requests can't both pass
                // the available-balance check.
                $account = SavingsAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

                if ($account->status !== AccountStatus::Active) {
                    throw ValidationException::withMessages(['account' => 'Withdrawals are only possible on active accounts.']);
                }
                if ($account->product->type === SavingsProductType::FixedDeposit && $account->matured_at === null) {
                    throw ValidationException::withMessages(['account' => 'Fixed deposits cannot be withdrawn before their maturity date.']);
                }

                // Share capital is redeemed in whole shares so the balance
                // always stays share_count * par_value.
                $parValue = (int) $account->product->par_value;
                if ($account->product->type === SavingsProductType::Shares && ($parValue <= 0 || $amount % $parValue !== 0)) {
                    throw ValidationException::withMessages([
                        'amount' => 'Share withdrawals must be a whole number of shares ('.$parValue.' per share).',
                    ]);
                }

                $held = (int) $account->withdrawalRequests()
                    ->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::Approved])
                    ->sum('amount');

                if ($amount <= 0 || $amount > $account->balance - $held) {
                    throw ValidationException::withMessages([
                        'amount' => 'Requested amount exceeds the available balance.',
                    ]);
                }

                return WithdrawalRequest::create([
                    'company_id' => $account->company_id,
                    'branch_id' => $account->branch_id,
                    'savings_account_id' => $account->id,
                    'customer_id' => $account->customer_id,
                    'amount' => $amount,
                    'penalty_amount' => $this->earlyWithdrawalPenalty($account, $amount),
                    'reason' => $reason,
                    'status' => WithdrawalStatus::Pending,
                    'requested_by' => $requestedBy->id,
                    'client_reference' => $clientReference,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent retry with the same reference won the race.
            if ($clientReference === null) {
                throw $e;
            }

            return WithdrawalRequest::where('client_reference', $clientReference)->firstOrFail();
        }
    }

    /** Applies only to target-savings accounts withdrawn from before their matures_at date. */
    private function earlyWithdrawalPenalty(SavingsAccount $account, int $amount): int
    {
        if ($account->product->type !== SavingsProductType::Target || $account->matured_at !== null) {
            return 0;
        }

        return intdiv($amount * $account->product->early_withdrawal_penalty_bps, 10_000);
    }
}
