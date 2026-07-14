<?php

namespace App\Actions\Groups;

use App\Enums\ClientOrigin;
use App\Enums\GroupRoundStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Group;
use App\Models\GroupContribution;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records one member's fixed contribution to their group's current round.
 * Idempotent on client_reference so an offline sync replay can never
 * double-post — mirrors RecordCollectionAction.
 */
class RecordGroupContributionAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
    ) {}

    public function execute(
        User $recordedBy,
        GroupMember $member,
        ?string $clientReference = null,
        ?CarbonInterface $recordedAt = null,
        ClientOrigin $origin = ClientOrigin::Web,
        PaymentMethod $paymentMethod = PaymentMethod::Cash,
    ): GroupContribution {
        if ($clientReference !== null) {
            $existing = GroupContribution::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $group = $member->group;
        $round = $group->currentRound();

        if ($round === null || $round->status === GroupRoundStatus::Completed) {
            throw ValidationException::withMessages(['group' => 'This group has no round currently collecting.']);
        }
        if ($round->contributions()->where('group_member_id', $member->id)->exists()) {
            throw ValidationException::withMessages(['member' => 'This member has already contributed to this round.']);
        }

        return DB::transaction(function () use ($group, $round, $member, $recordedBy, $clientReference, $recordedAt, $origin, $paymentMethod): GroupContribution {
            $amount = $group->contribution_amount;
            $effectiveRecordedAt = $recordedAt ?? now();

            $debitAccount = match ($paymentMethod) {
                PaymentMethod::MobileMoney => $this->chart->momoClearing($group->company),
                default => $this->chart->agentCash($recordedBy),
            };

            $entry = $this->ledger->post(new EntryData(
                company: $group->company,
                type: TransactionType::GroupContribution,
                lines: [
                    ['account' => $debitAccount, 'debit' => $amount],
                    ['account' => $group->liabilityAccount, 'credit' => $amount],
                ],
                branch: $group->branch,
                paymentMethod: $paymentMethod,
                origin: $origin,
                recordedBy: $recordedBy,
                recordedAt: $effectiveRecordedAt,
                clientReference: $clientReference,
                description: "Group contribution {$group->code} round {$round->round_number}",
                meta: [
                    'customer_id' => $member->customer_id,
                    'group_id' => $group->id,
                    'group_round_id' => $round->id,
                    'amount' => $amount,
                ],
            ));

            $contribution = GroupContribution::create([
                'group_round_id' => $round->id,
                'group_member_id' => $member->id,
                'journal_entry_id' => $entry->id,
                'recorded_by' => $recordedBy->id,
                'amount' => $amount,
                'recorded_at' => $effectiveRecordedAt,
                'client_reference' => $clientReference,
            ]);

            $round->increment('total_collected', $amount);
            if ($round->status === GroupRoundStatus::Pending) {
                $round->forceFill(['status' => GroupRoundStatus::Collecting])->save();
            }

            return $contribution;
        });
    }
}
