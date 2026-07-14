<?php

use App\Actions\Groups\ActivateGroupAction;
use App\Actions\Groups\AddGroupMemberAction;
use App\Actions\Groups\PayoutGroupRoundAction;
use App\Actions\Groups\RecordGroupContributionAction;
use App\Enums\GroupRoundStatus;
use App\Enums\GroupStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Group;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();

    $this->group = Group::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'contribution_amount' => 1000, // GHS 10.00
    ]);

    $this->members = collect(range(1, 3))->map(function (int $position) {
        $customer = Customer::factory()->forBranch($this->branch)->create();

        return app(AddGroupMemberAction::class)->execute($this->group, $customer, $position);
    });
});

it('activates a group and generates one round per member in rotation order', function (): void {
    $activated = app(ActivateGroupAction::class)->execute($this->group->fresh());

    expect($activated->status)->toBe(GroupStatus::Active)
        ->and($activated->activated_at)->not->toBeNull();

    $rounds = $activated->rounds()->orderBy('round_number')->get();
    expect($rounds)->toHaveCount(3)
        ->and($rounds[0]->status)->toBe(GroupRoundStatus::Collecting)
        ->and($rounds[1]->status)->toBe(GroupRoundStatus::Pending)
        ->and($rounds[2]->status)->toBe(GroupRoundStatus::Pending);

    foreach ($rounds as $index => $round) {
        expect($round->payoutMember->rotation_position)->toBe($index + 1)
            ->and($round->total_expected)->toBe(3000); // 3 members x 1000
    }

    // Due dates advance by the group's frequency (monthly), one period apart.
    expect($rounds[0]->due_date->lessThan($rounds[1]->due_date))->toBeTrue()
        ->and($rounds[1]->due_date->lessThan($rounds[2]->due_date))->toBeTrue();
});

it('refuses to activate a group with fewer than 2 members', function (): void {
    $group = Group::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);
    $customer = Customer::factory()->forBranch($this->branch)->create();
    app(AddGroupMemberAction::class)->execute($group, $customer, 1);

    app(ActivateGroupAction::class)->execute($group);
})->throws(ValidationException::class);

it('refuses to activate a group with non-contiguous rotation positions', function (): void {
    $group = Group::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);
    app(AddGroupMemberAction::class)->execute($group, Customer::factory()->forBranch($this->branch)->create(), 1);
    app(AddGroupMemberAction::class)->execute($group, Customer::factory()->forBranch($this->branch)->create(), 5);

    app(ActivateGroupAction::class)->execute($group);
})->throws(ValidationException::class);

it('refuses to add a member once the group is active', function (): void {
    app(ActivateGroupAction::class)->execute($this->group->fresh());
    $customer = Customer::factory()->forBranch($this->branch)->create();

    app(AddGroupMemberAction::class)->execute($this->group->fresh(), $customer, 4);
})->throws(ValidationException::class);

it('collects contributions from every member and pays out the round to the rotation member', function (): void {
    $group = app(ActivateGroupAction::class)->execute($this->group->fresh());
    $round = $group->currentRound();

    foreach ($this->members as $member) {
        app(RecordGroupContributionAction::class)->execute($this->agent, $member->fresh());
    }

    $round->refresh();
    expect($round->total_collected)->toBe(3000)
        ->and($round->contributions()->count())->toBe(3);

    $paid = app(PayoutGroupRoundAction::class)->execute($round, $this->manager);

    expect($paid->status)->toBe(GroupRoundStatus::Completed)
        ->and($paid->payout_entry_id)->not->toBeNull();

    // Branch cash starts unfunded in this test (no remittances), so paying
    // out reduces it below zero — the point here is the group liability
    // account returning to zero (the pot is empty again), not the absolute
    // branch cash balance.
    $branchCash = app(ChartOfAccounts::class)->branchCash($this->branch);
    $groupLiability = app(ChartOfAccounts::class)->groupLiability($group->fresh());

    expect($branchCash->refresh()->balance)->toBe(-3000)
        ->and($groupLiability->refresh()->balance)->toBe(0); // fully paid out, pot empty again

    // Next round auto-starts collecting.
    $nextRound = $group->fresh()->rounds()->where('round_number', 2)->first();
    expect($nextRound->status)->toBe(GroupRoundStatus::Collecting);
});

it('rejects a duplicate contribution from the same member for the same round', function (): void {
    $group = app(ActivateGroupAction::class)->execute($this->group->fresh());
    $member = $this->members->first()->fresh();

    app(RecordGroupContributionAction::class)->execute($this->agent, $member);
    app(RecordGroupContributionAction::class)->execute($this->agent, $member);
})->throws(ValidationException::class);

it('is idempotent when the same client_reference is replayed', function (): void {
    app(ActivateGroupAction::class)->execute($this->group->fresh());
    $member = $this->members->first()->fresh();
    $ref = (string) Str::uuid();

    $first = app(RecordGroupContributionAction::class)->execute($this->agent, $member, clientReference: $ref);
    $second = app(RecordGroupContributionAction::class)->execute($this->agent, $member->fresh(), clientReference: $ref);

    expect($second->id)->toBe($first->id)
        ->and($member->fresh()->group->currentRound()->total_collected)->toBe(1000); // only applied once
});

it('refuses to pay out a round that is not fully collected without an override', function (): void {
    $group = app(ActivateGroupAction::class)->execute($this->group->fresh());
    $round = $group->currentRound();

    app(RecordGroupContributionAction::class)->execute($this->agent, $this->members->first()->fresh());

    app(PayoutGroupRoundAction::class)->execute($round->fresh(), $this->manager);
})->throws(ValidationException::class);

it('allows an early payout with the override flag', function (): void {
    $group = app(ActivateGroupAction::class)->execute($this->group->fresh());
    $round = $group->currentRound();

    app(RecordGroupContributionAction::class)->execute($this->agent, $this->members->first()->fresh());

    $paid = app(PayoutGroupRoundAction::class)->execute($round->fresh(), $this->manager, override: true);

    expect($paid->status)->toBe(GroupRoundStatus::Completed)
        ->and($paid->total_collected)->toBe(1000); // only what was actually collected is paid out
});

it('completes the group once every round has been paid out', function (): void {
    $group = app(ActivateGroupAction::class)->execute($this->group->fresh());

    for ($i = 0; $i < 3; $i++) {
        $round = $group->fresh()->currentRound();
        foreach ($this->members as $member) {
            app(RecordGroupContributionAction::class)->execute($this->agent, $member->fresh());
        }
        app(PayoutGroupRoundAction::class)->execute($round->fresh(), $this->manager);
    }

    expect($group->fresh()->status)->toBe(GroupStatus::Completed)
        ->and($group->fresh()->completed_at)->not->toBeNull();

    // Ledger stays balanced across the whole rotation.
    $ledger = app(LedgerService::class);
    $groupLiability = app(ChartOfAccounts::class)->groupLiability($group->fresh());
    expect($ledger->recomputeBalance($groupLiability))->toBe(0);
});
