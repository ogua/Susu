<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\JournalEntry;
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
