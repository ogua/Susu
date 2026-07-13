<?php

namespace App\Actions\Savings;

use App\Enums\AccountStatus;
use App\Enums\ClientOrigin;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\AgentDailySummary;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use App\Services\Savings\CommissionCalculator;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a susu deposit: Dr agent cash / Cr customer savings liability,
 * plus the commission entry when a new cycle starts (AD-2 posting patterns).
 * Idempotent on client_reference so offline replays can never double-post.
 */
class RecordCollectionAction
{
    /** Entries recorded more than this many hours ago get flagged for review (G8). */
    private const STALE_HOURS = 48;

    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
        private CommissionCalculator $commission,
    ) {}

    public function execute(
        User $agent,
        SavingsAccount $account,
        int $amount,
        ?string $clientReference = null,
        ?CarbonInterface $recordedAt = null,
        ClientOrigin $origin = ClientOrigin::Web,
        PaymentMethod $paymentMethod = PaymentMethod::Cash,
        ?float $latitude = null,
        ?float $longitude = null,
    ): CollectionResult {
        if ($clientReference !== null) {
            $existing = $this->ledger->findByClientReference($clientReference);
            if ($existing !== null) {
                return new CollectionResult($existing, $account, 0, duplicate: true);
            }
        }

        $this->assertRecordable($agent, $account, $amount);

        $recordedAt ??= now();
        $units = intdiv($amount, $account->contribution_amount);

        return DB::transaction(function () use (
            $agent, $account, $amount, $units, $clientReference,
            $recordedAt, $origin, $paymentMethod, $latitude, $longitude
        ): CollectionResult {
            /** @var SavingsAccount $account */
            $account = SavingsAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

            $cycle = $this->commission->simulate($account, $units, $amount);
            $balanceAfter = $account->balance + $amount - $cycle->commissionAmount;

            $meta = [
                'customer_id' => $account->customer_id,
                'savings_account_id' => $account->id,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
            ];
            if ($recordedAt->diffInHours(now()) > self::STALE_HOURS) {
                $meta['flagged_stale'] = true;
            }

            // Cash sits with the agent until remitted; mobile money never touches the
            // agent's hand at all — it settles from Paystack to the company's bank —
            // so the two payment methods must debit different asset accounts or the
            // agent's cash-in-hand (used for day-close reconciliation) would be wrong.
            $debitAccount = match ($paymentMethod) {
                PaymentMethod::MobileMoney => $this->chart->momoClearing($account->company),
                default => $this->chart->agentCash($agent),
            };

            $entry = $this->ledger->post(new EntryData(
                company: $account->company,
                type: TransactionType::Collection,
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
                description: "Susu collection {$account->account_number}",
                latitude: $latitude,
                longitude: $longitude,
                meta: $meta,
            ));

            if ($cycle->commissionAmount > 0) {
                $this->ledger->post(new EntryData(
                    company: $account->company,
                    type: TransactionType::Commission,
                    lines: [
                        ['account' => $account->ledgerAccount, 'debit' => $cycle->commissionAmount],
                        ['account' => $this->chart->commissionIncome($account->company), 'credit' => $cycle->commissionAmount],
                    ],
                    branch: $account->branch,
                    paymentMethod: PaymentMethod::Internal,
                    origin: ClientOrigin::System,
                    recordedBy: $agent,
                    recordedAt: $recordedAt,
                    description: "Cycle commission {$account->account_number}",
                    meta: [
                        'customer_id' => $account->customer_id,
                        'savings_account_id' => $account->id,
                        'collection_entry_id' => $entry->id,
                    ],
                ));
            }

            $account->forceFill([
                'contributions_this_cycle' => $cycle->newContributionsThisCycle,
                'cycle_number' => $cycle->newCycleNumber,
                'cycle_started_at' => $cycle->cyclesStarted > 0
                    ? $recordedAt->toDateString()
                    : $account->cycle_started_at,
                'balance' => $balanceAfter,
                'status' => AccountStatus::Active,
            ])->save();

            // Self-service customer momo deposits have no day sheet to track — only
            // staff roles carry cash-in-hand/reconciliation responsibility.
            if ($agent->hasAnyRole(['field_agent', 'branch_manager', 'company_admin'])) {
                $this->trackDailySummary($agent, $account, $amount, $recordedAt);
            }

            return new CollectionResult($entry, $account, $cycle->commissionAmount, duplicate: false);
        });
    }

    private function assertRecordable(User $agent, SavingsAccount $account, int $amount): void
    {
        if ($account->status === AccountStatus::Closed) {
            throw ValidationException::withMessages(['account' => 'This savings account is closed.']);
        }

        $isAssignedAgent = $account->agent_id === $agent->id;
        $isManager = $agent->hasRole(['branch_manager', 'company_admin']);
        $isOwnAccount = $account->customer?->user_id === $agent->id;

        if (! $isAssignedAgent && ! $isManager && ! $isOwnAccount) {
            throw ValidationException::withMessages(['account' => 'You are not assigned to this account.']);
        }

        if ($agent->company_id !== $account->company_id) {
            throw ValidationException::withMessages(['account' => 'Account not found.']);
        }

        if ($amount <= 0 || $amount % $account->contribution_amount !== 0) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be a positive multiple of the daily contribution ('.$account->contribution_amount.').',
            ]);
        }
    }

    /**
     * Keep the agent's day sheet running. The summary auto-opens on the first
     * collection of the day so offline field work is never blocked.
     */
    private function trackDailySummary(User $agent, SavingsAccount $account, int $amount, CarbonInterface $recordedAt): void
    {
        $summary = AgentDailySummary::firstOrCreate(
            ['agent_id' => $agent->id, 'summary_date' => $recordedAt->toDateString()],
            [
                'company_id' => $account->company_id,
                'branch_id' => $account->branch_id,
            ],
        );

        $summary->increment('collections_total', $amount);
        $summary->increment('collections_count');
    }
}
