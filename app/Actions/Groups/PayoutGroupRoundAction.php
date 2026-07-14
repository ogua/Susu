<?php

namespace App\Actions\Groups;

use App\Enums\ClientOrigin;
use App\Enums\GroupRoundStatus;
use App\Enums\GroupStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\GroupRound;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pays the pooled round out to its rotation-designated member: Dr group
 * liability / Cr branch cash. Requires the round to be fully collected
 * unless $override is set (an authorized early/partial payout — the gate
 * the plan calls for). Auto-starts the next round in the rotation, or
 * closes the group out once the last round is paid.
 */
class PayoutGroupRoundAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
    ) {}

    public function execute(GroupRound $round, User $paidBy, bool $override = false): GroupRound
    {
        if ($round->status === GroupRoundStatus::Completed) {
            throw ValidationException::withMessages(['round' => 'This round has already been paid out.']);
        }
        if (! $override && $round->total_collected < $round->total_expected) {
            throw ValidationException::withMessages([
                'round' => 'This round has not been fully collected yet. Use the override to pay out early.',
            ]);
        }
        if ($round->total_collected <= 0) {
            throw ValidationException::withMessages(['round' => 'Nothing has been collected for this round yet.']);
        }

        return DB::transaction(function () use ($round, $paidBy): GroupRound {
            $group = $round->group;
            $payoutMember = $round->payoutMember;
            $amount = $round->total_collected;

            $entry = $this->ledger->post(new EntryData(
                company: $group->company,
                type: TransactionType::GroupPayout,
                lines: [
                    ['account' => $group->liabilityAccount, 'debit' => $amount],
                    ['account' => $this->chart->branchCash($group->branch), 'credit' => $amount],
                ],
                branch: $group->branch,
                paymentMethod: PaymentMethod::Cash,
                origin: ClientOrigin::Web,
                recordedBy: $paidBy,
                description: "Group payout {$group->code} round {$round->round_number}",
                meta: [
                    'customer_id' => $payoutMember->customer_id,
                    'group_id' => $group->id,
                    'group_round_id' => $round->id,
                    'amount' => $amount,
                ],
            ));

            $round->forceFill([
                'status' => GroupRoundStatus::Completed,
                'payout_entry_id' => $entry->id,
                'paid_out_at' => now(),
            ])->save();

            $nextRound = $group->rounds()->where('round_number', $round->round_number + 1)->first();
            if ($nextRound !== null) {
                $nextRound->forceFill(['status' => GroupRoundStatus::Collecting])->save();
            } else {
                $group->forceFill(['status' => GroupStatus::Completed, 'completed_at' => now()])->save();
            }

            return $round->fresh();
        });
    }
}
