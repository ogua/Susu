<?php

use App\Actions\Groups\ActivateGroupAction;
use App\Actions\Groups\AddGroupMemberAction;
use App\Actions\Loans\ApplyForLoanAction;
use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Group;
use App\Models\JournalEntry;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SyncOp;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $this->product = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
    ]);

    $this->customer = Customer::factory()->forBranch($this->branch)->create();

    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);
});

function collectionOp(string $accountId, int $amount, ?string $opId = null): array
{
    return [
        'op_id' => $opId ?? (string) Str::uuid(),
        'op_type' => 'collection.record',
        'payload' => ['savings_account_id' => $accountId, 'amount' => $amount],
        'recorded_at' => now()->toISOString(),
    ];
}

it('applies a batch of offline ops and posts to the ledger', function (): void {
    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [collectionOp($this->account->id, 500)],
    ]);

    $response->assertOk()->assertJsonPath('results.0.status', 'applied');
    expect($this->account->refresh()->contributions_this_cycle)->toBe(1);
});

it('returns duplicate and does not double-post when the same op_id is replayed', function (): void {
    $op = collectionOp($this->account->id, 500);

    $this->actingAs($this->agent, 'sanctum')
        ->postJson('/api/v1/sync/batch', ['ops' => [$op]])
        ->assertOk()->assertJsonPath('results.0.status', 'applied');

    $second = $this->actingAs($this->agent, 'sanctum')
        ->postJson('/api/v1/sync/batch', ['ops' => [$op]]);

    $second->assertOk()->assertJsonPath('results.0.status', 'duplicate');
    expect(SyncOp::where('op_id', $op['op_id'])->count())->toBe(1)
        ->and($this->account->refresh()->contributions_this_cycle)->toBe(1); // not applied twice
});

it('reports partial success across a mixed batch', function (): void {
    $goodOp = collectionOp($this->account->id, 500);
    $badOp = collectionOp($this->account->id, 300); // not a multiple of contribution_amount

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [$goodOp, $badOp],
    ]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.1.status', 'rejected');
});

it('flags stale recorded_at instead of rejecting it', function (): void {
    $op = collectionOp($this->account->id, 500);
    $op['recorded_at'] = now()->subDays(5)->toISOString();

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', ['ops' => [$op]]);

    $response->assertOk()->assertJsonPath('results.0.status', 'applied');

    $entry = JournalEntry::find($response->json('results.0.result.entry_id'));
    expect($entry->meta['flagged_stale'] ?? false)->toBeTrue();
});

it('rejects op types not allowed for the caller role', function (): void {
    $customerUser = User::factory()->customerUser()->create(['company_id' => $this->branch->company_id]);

    $response = $this->actingAs($customerUser, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [collectionOp($this->account->id, 500)],
    ]);

    $response->assertForbidden();
});

it('applies a full offline provisioning chain in one batch with matching ids', function (): void {
    // This is the desktop/mobile hybrid-mode scenario: a customer is registered,
    // a savings account opened for them, and a collection recorded against it —
    // all while offline — using client-generated UUIDs as the record ids so the
    // whole chain resolves correctly the moment it's replayed in a single batch.
    $customerRef = (string) Str::uuid();
    $accountRef = (string) Str::uuid();

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [
            [
                'op_id' => (string) Str::uuid(),
                'op_type' => 'customer.register',
                'payload' => [
                    'first_name' => 'Ama',
                    'last_name' => 'Serwaa',
                    'phone' => '0244000111',
                    'client_reference' => $customerRef,
                ],
                'recorded_at' => now()->toISOString(),
            ],
            [
                'op_id' => (string) Str::uuid(),
                'op_type' => 'account.open',
                'payload' => [
                    'customer_id' => $customerRef,
                    'savings_product_id' => $this->product->id,
                    'client_reference' => $accountRef,
                ],
                'recorded_at' => now()->toISOString(),
            ],
            collectionOp($accountRef, 500),
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.0.result.customer_id', $customerRef)
        ->assertJsonPath('results.1.status', 'applied')
        ->assertJsonPath('results.1.result.account_id', $accountRef)
        ->assertJsonPath('results.2.status', 'applied');

    expect(Customer::find($customerRef))->not->toBeNull()
        ->and(SavingsAccount::find($accountRef))->not->toBeNull()
        ->and(SavingsAccount::find($accountRef)->contributions_this_cycle)->toBe(1);
});

it('registers a customer with nested identifications and beneficiaries through the sync batch, idempotently on replay', function (): void {
    $customerRef = (string) Str::uuid();
    $opId = (string) Str::uuid();

    $batch = [
        'ops' => [
            [
                'op_id' => $opId,
                'op_type' => 'customer.register',
                'payload' => [
                    'first_name' => 'Kofi',
                    'last_name' => 'Owusu',
                    'phone' => '0244555666',
                    'client_reference' => $customerRef,
                    'identifications' => [
                        [
                            'id_type' => 'ghana_card',
                            'id_number' => 'GHA-555666777-1',
                            'issue_date' => '2026-01-01',
                            'is_primary' => true,
                        ],
                    ],
                    'beneficiaries' => [
                        ['name' => 'Abena Owusu', 'relationship' => 'daughter', 'amount_of_legacy' => 1000],
                    ],
                ],
                'recorded_at' => now()->toISOString(),
            ],
        ],
    ];

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', $batch);
    $response->assertOk()->assertJsonPath('results.0.status', 'applied');

    $customer = Customer::findOrFail($customerRef);
    expect($customer->identifications)->toHaveCount(1)
        ->and($customer->id_number)->toBe('GHA-555666777-1')
        ->and($customer->beneficiaries)->toHaveCount(1);

    // Replaying the identical batch (same op_id) must not duplicate the child rows.
    $replay = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', $batch);
    $replay->assertOk()->assertJsonPath('results.0.status', 'duplicate');

    expect($customer->identifications()->count())->toBe(1)
        ->and($customer->beneficiaries()->count())->toBe(1);
});

it('lets a branch manager approve and disburse a loan through the sync batch', function (): void {
    // This is the desktop hybrid-mode scenario: company_admin approves/
    // disburses locally, then the outcome is synced up for audit visibility.
    $manager = User::factory()->branchManager($this->branch)->create();

    $product = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'min_amount' => 100_00,
        'max_amount' => 1_000_00,
    ]);

    $loan = app(ApplyForLoanAction::class)->execute(
        submittedBy: $this->agent,
        customer: $this->customer,
        product: $product,
        requestedAmount: 300_00,
    );

    $response = $this->actingAs($manager, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [
            [
                'op_id' => (string) Str::uuid(),
                'op_type' => 'loan.approve',
                'payload' => ['loan_id' => $loan->id],
                'recorded_at' => now()->toISOString(),
            ],
            [
                'op_id' => (string) Str::uuid(),
                'op_type' => 'loan.disburse',
                'payload' => ['loan_id' => $loan->id],
                'recorded_at' => now()->toISOString(),
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.0.result.status', 'approved')
        ->assertJsonPath('results.1.status', 'applied')
        ->assertJsonPath('results.1.result.status', 'disbursed');

    expect($loan->refresh()->status)->toBe(LoanStatus::Disbursed);
});

it('rejects loan approve/disburse ops from a field agent', function (): void {
    $product = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'min_amount' => 100_00,
        'max_amount' => 1_000_00,
    ]);

    $loan = app(ApplyForLoanAction::class)->execute(
        submittedBy: $this->agent,
        customer: $this->customer,
        product: $product,
        requestedAmount: 300_00,
    );

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [[
            'op_id' => (string) Str::uuid(),
            'op_type' => 'loan.approve',
            'payload' => ['loan_id' => $loan->id],
            'recorded_at' => now()->toISOString(),
        ]],
    ]);

    $response->assertOk()->assertJsonPath('results.0.status', 'rejected');
    expect($loan->refresh()->status)->toBe(LoanStatus::Applied);
});

it('lets a field agent record a group contribution through the sync batch', function (): void {
    $group = Group::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'contribution_amount' => 1000,
    ]);
    $customerOne = Customer::factory()->forBranch($this->branch)->create();
    $customerTwo = Customer::factory()->forBranch($this->branch)->create();
    $memberOne = app(AddGroupMemberAction::class)->execute($group, $customerOne, 1);
    app(AddGroupMemberAction::class)->execute($group, $customerTwo, 2);
    app(ActivateGroupAction::class)->execute($group->fresh());

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [[
            'op_id' => (string) Str::uuid(),
            'op_type' => 'group.contribution.record',
            'payload' => ['group_member_id' => $memberOne->id],
            'recorded_at' => now()->toISOString(),
        ]],
    ]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.0.result.amount', 1000);
});
