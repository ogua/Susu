<?php

namespace App\Actions\Savings;

use App\Enums\AccountStatus;
use App\Enums\ClientOrigin;
use App\Enums\PaymentMethod;
use App\Enums\SavingsProductType;
use App\Enums\TransactionType;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buys shares into a cooperative share-capital account: Dr agent cash (or
 * momo clearing) / Cr the account's savings liability, for
 * shares * product.par_value. Deliberately a separate Action from
 * RecordCollectionAction — that Action is irreducibly susu-cycle-specific
 * (contribution-amount multiples, CommissionCalculator, cycle rollover),
 * none of which has meaning for a discrete share purchase. Idempotent on
 * client_reference, mirrors RecordCollectionAction's dedup shape.
 */
class BuySharesAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
    ) {}

    public function execute(
        User $agent,
        SavingsAccount $account,
        int $shares,
        ?string $clientReference = null,
        ?CarbonInterface $recordedAt = null,
        ClientOrigin $origin = ClientOrigin::Web,
        PaymentMethod $paymentMethod = PaymentMethod::Cash,
    ): CollectionResult {
        if ($clientReference !== null) {
            $existing = $this->ledger->findByClientReference($clientReference);
            if ($existing !== null) {
                return new CollectionResult($existing, $account, 0, duplicate: true);
            }
        }

        $this->assertPurchasable($agent, $account, $shares);

        $recordedAt ??= now();
        $amount = $shares * $account->product->par_value;

        return DB::transaction(function () use (
            $agent, $account, $shares, $amount, $clientReference, $recordedAt, $origin, $paymentMethod
        ): CollectionResult {
            /** @var SavingsAccount $account */
            $account = SavingsAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

            $debitAccount = match ($paymentMethod) {
                PaymentMethod::MobileMoney => $this->chart->momoClearing($account->company),
                default => $this->chart->agentCash($agent),
            };

            $entry = $this->ledger->post(new EntryData(
                company: $account->company,
                type: TransactionType::SharesPurchase,
                lines: [
                    ['account' => $debitAccount, 'debit' => $amount],
                    ['account' => $account->ledgerAccount, 'credit' => $amount],
                ],
                branch: $account->branch,
                paymentMethod: $paymentMethod,
                origin: $origin,
                recordedBy: $agent,
                recordedAt: $recordedAt,
                clientReference: $clientReference,
                description: "Share purchase {$account->account_number} ({$shares} shares)",
                meta: [
                    'customer_id' => $account->customer_id,
                    'savings_account_id' => $account->id,
                    'shares' => $shares,
                    'amount' => $amount,
                ],
            ));

            $account->forceFill([
                'share_count' => $account->share_count + $shares,
                'balance' => $account->balance + $amount,
                'status' => AccountStatus::Active,
            ])->save();

            return new CollectionResult($entry, $account, 0, duplicate: false);
        });
    }

    public function assertPurchasable(User $agent, SavingsAccount $account, int $shares): void
    {
        if ($account->product->type !== SavingsProductType::Shares) {
            throw ValidationException::withMessages(['account' => 'This account does not support share purchases.']);
        }
        if ($account->status === AccountStatus::Closed) {
            throw ValidationException::withMessages(['account' => 'This savings account is closed.']);
        }
        if ($agent->company_id !== $account->company_id) {
            throw ValidationException::withMessages(['account' => 'Account not found.']);
        }

        $isAssignedAgent = $account->agent_id === $agent->id;
        $isManager = $agent->hasRole(['branch_manager', 'company_admin']);
        $isOwnAccount = $account->customer?->user_id === $agent->id;

        if (! $isAssignedAgent && ! $isManager && ! $isOwnAccount) {
            throw ValidationException::withMessages(['account' => 'You are not assigned to this account.']);
        }

        if ($shares <= 0) {
            throw ValidationException::withMessages(['shares' => 'Must purchase at least one share.']);
        }
    }
}
