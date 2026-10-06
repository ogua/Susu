<?php

use App\Enums\GroupRoundStatus;
use App\Enums\GroupStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
});

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function groupOp(string $type, array $payload): array
{
    return [
        'op_id' => (string) Str::uuid(),
        'op_type' => $type,
        'payload' => $payload,
        'recorded_at' => now()->toISOString(),
    ];
}

it('applies a whole offline group lifecycle in one batch, keeping the client group id', function (): void {
    $groupId = (string) Str::uuid();
    $firstMemberId = (string) Str::uuid();
    [$first, $second] = Customer::factory()->forBranch($this->branch)->count(2)->create();

    $response = $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [
            groupOp('group.create', [
                'name' => 'Market Women',
                'code' => 'MW-01',
                'contribution_amount' => 50_00,
                'frequency' => 'weekly',
                'client_reference' => $groupId,
            ]),
            groupOp('group.member.add', ['group_id' => $groupId, 'customer_id' => $first->id, 'rotation_position' => 1, 'client_reference' => $firstMemberId]),
            groupOp('group.member.add', ['group_id' => $groupId, 'customer_id' => $second->id, 'rotation_position' => 2]),
            groupOp('group.activate', ['group_id' => $groupId]),
            // The contribution names the member by the id this device gave it.
            groupOp('group.contribution.record', ['group_member_id' => $firstMemberId]),
            groupOp('group.payout', ['group_id' => $groupId, 'round_number' => 1, 'override' => true]),
        ],
    ])->assertOk();

    foreach (range(0, 5) as $index) {
        $response->assertJsonPath("results.{$index}.status", 'applied');
    }
    $response->assertJsonPath('results.0.result.group_id', $groupId)
        ->assertJsonPath('results.5.result.status', 'completed');

    $group = Group::findOrFail($groupId);
    expect($group->status)->toBe(GroupStatus::Active)
        ->and($group->branch_id)->toBe($this->branch->id)
        ->and($group->members()->count())->toBe(2)
        ->and($group->rounds()->where('round_number', 1)->first()->status)->toBe(GroupRoundStatus::Completed);
});

it('rejects a duplicate group code as a validation error, not a retryable failure', function (): void {
    Group::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id, 'code' => 'MW-01']);

    $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [groupOp('group.create', [
            'name' => 'Market Women',
            'code' => 'MW-01',
            'contribution_amount' => 50_00,
            'frequency' => 'weekly',
        ])],
    ])->assertJsonPath('results.0.status', 'rejected')
        ->assertJsonMissingPath('results.0.retryable')
        ->assertJsonPath('results.0.result.errors.0', 'A group with this code already exists.');
});

it('rejects group setup ops from a field agent', function (string $type): void {
    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [groupOp($type, ['group_id' => (string) Str::uuid()])],
    ])->assertJsonPath('results.0.result.errors.0', 'This operation is not allowed for your role.');
})->with(['group.create', 'group.member.add', 'group.activate', 'group.payout']);
