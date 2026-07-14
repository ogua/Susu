<?php

use App\Actions\Customers\ProvisionCustomerLoginAction;
use App\Actions\Groups\ActivateGroupAction;
use App\Actions\Groups\AddGroupMemberAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Group;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();

    $this->group = Group::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'contribution_amount' => 1000,
    ]);

    $this->customerOne = Customer::factory()->forBranch($this->branch)->create();
    $this->customerTwo = Customer::factory()->forBranch($this->branch)->create();

    $this->memberOne = app(AddGroupMemberAction::class)->execute($this->group, $this->customerOne, 1);
    $this->memberTwo = app(AddGroupMemberAction::class)->execute($this->group, $this->customerTwo, 2);

    $this->customerUser = app(ProvisionCustomerLoginAction::class)->execute($this->customerOne, 'password');
});

it('lets an agent list groups in their branch', function (): void {
    $response = $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/groups');

    $response->assertOk()->assertJsonPath('data.0.id', $this->group->id);
});

it('only shows a customer the groups they belong to', function (): void {
    $otherGroup = Group::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
    ]);

    $response = $this->actingAs($this->customerUser, 'sanctum')->getJson('/api/v1/groups');

    $response->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->group->id);
});

it('lets an agent record a contribution for a member', function (): void {
    app(ActivateGroupAction::class)->execute($this->group->fresh());

    $response = $this->actingAs($this->agent, 'sanctum')->postJson(
        "/api/v1/groups/{$this->group->id}/contributions",
        ['group_member_id' => $this->memberOne->id],
    );

    $response->assertCreated()->assertJsonPath('amount', 1000);
});

it('rejects a customer trying to record a contribution', function (): void {
    app(ActivateGroupAction::class)->execute($this->group->fresh());

    $this->actingAs($this->customerUser, 'sanctum')->postJson(
        "/api/v1/groups/{$this->group->id}/contributions",
        ['group_member_id' => $this->memberOne->id],
    )->assertForbidden();
});

it('lets a manager pay out a fully collected round', function (): void {
    $group = app(ActivateGroupAction::class)->execute($this->group->fresh());
    $round = $group->currentRound();

    $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/groups/{$group->id}/contributions", ['group_member_id' => $this->memberOne->id]);
    $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/groups/{$group->id}/contributions", ['group_member_id' => $this->memberTwo->id]);

    $response = $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/groups/rounds/{$round->id}/payout");

    $response->assertOk()->assertJsonPath('status', 'completed');
});

it('rejects a field agent trying to pay out a round', function (): void {
    $group = app(ActivateGroupAction::class)->execute($this->group->fresh());
    $round = $group->currentRound();

    $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/groups/rounds/{$round->id}/payout")
        ->assertForbidden();
});
