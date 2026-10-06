<?php

namespace App\Actions\Agents;

use App\Enums\ClientOrigin;
use App\Enums\TransactionType;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use App\Support\StaffBranch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agent hands collected cash to the branch: Dr branch cash / Cr agent cash.
 */
class RecordAgentRemittanceAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
    ) {}

    public function execute(
        User $agent,
        int $amount,
        ?User $receivedBy = null,
        ?string $clientReference = null,
        ClientOrigin $origin = ClientOrigin::Web,
    ): JournalEntry {
        if ($clientReference !== null) {
            $existing = $this->ledger->findByClientReference($clientReference);
            if ($existing !== null) {
                return $existing;
            }
        }

        $branch = $agent->branch ?? StaffBranch::resolve($agent);
        $branchCash = $this->chart->branchCash($branch);
        $agentCashId = $this->chart->agentCash($agent)->id;

        return DB::transaction(function () use ($agent, $amount, $receivedBy, $clientReference, $origin, $branch, $branchCash, $agentCashId): JournalEntry {
            // Lock the agent's cash account so concurrent remittances can't
            // both pass the balance check and drive it negative.
            $agentCash = LedgerAccount::whereKey($agentCashId)->lockForUpdate()->firstOrFail();

            if ($amount <= 0 || $amount > $agentCash->balance) {
                throw ValidationException::withMessages([
                    'amount' => 'Remittance exceeds the cash this agent is holding ('.$agentCash->balance.').',
                ]);
            }

            return $this->ledger->post(new EntryData(
                company: $agent->company,
                type: TransactionType::Remittance,
                lines: [
                    ['account' => $branchCash, 'debit' => $amount],
                    ['account' => $agentCash, 'credit' => $amount],
                ],
                branch: $branch,
                origin: $origin,
                recordedBy: $receivedBy ?? $agent,
                clientReference: $clientReference,
                description: "Cash remittance from {$agent->name}",
                meta: ['agent_id' => $agent->id, 'amount' => $amount],
            ));
        });
    }
}
