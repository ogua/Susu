<?php

namespace App\Actions\Groups;

use App\Enums\GroupRoundStatus;
use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\GroupRound;
use App\Services\Ledger\ChartOfAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Locks in the rotation and generates every round upfront (one per member,
 * in rotation_position order) — the schedule is fixed the moment a group
 * goes active, exactly like a loan's installment schedule is fixed at
 * disbursement.
 */
class ActivateGroupAction
{
    public function __construct(private ChartOfAccounts $chart) {}

    public function execute(Group $group): Group
    {
        if ($group->status !== GroupStatus::Draft) {
            throw ValidationException::withMessages(['group' => 'Only draft groups can be activated.']);
        }

        $members = $group->members()->where('status', 'active')->get();
        if ($members->count() < 2) {
            throw ValidationException::withMessages(['group' => 'A group needs at least 2 members to activate.']);
        }

        $positions = $members->pluck('rotation_position')->sort()->values()->all();
        $expected = range(1, $members->count());
        if ($positions !== $expected) {
            throw ValidationException::withMessages([
                'group' => 'Rotation positions must be contiguous from 1 to the member count.',
            ]);
        }

        return DB::transaction(function () use ($group, $members): Group {
            $liabilityAccount = $this->chart->groupLiability($group);
            $activatedAt = now();
            $totalExpected = $group->contribution_amount * $members->count();
            $dueDate = $activatedAt->copy();

            foreach ($members->sortBy('rotation_position')->values() as $index => $member) {
                $roundNumber = $index + 1;
                $dueDate = $group->frequency->addPeriod($dueDate);

                GroupRound::create([
                    'group_id' => $group->id,
                    'payout_member_id' => $member->id,
                    'round_number' => $roundNumber,
                    'due_date' => $dueDate->toDateString(),
                    'total_expected' => $totalExpected,
                    'total_collected' => 0,
                    'status' => $roundNumber === 1 ? GroupRoundStatus::Collecting : GroupRoundStatus::Pending,
                ]);
            }

            $group->forceFill([
                'liability_account_id' => $liabilityAccount->id,
                'status' => GroupStatus::Active,
                'activated_at' => $activatedAt,
            ])->save();

            return $group->fresh();
        });
    }
}
