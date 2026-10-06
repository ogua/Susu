<?php

use App\Enums\WithdrawalStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->customer = Customer::factory()->forBranch($this->branch)->create();

    $this->account = SavingsAccount::factory()->funded(1_000_00)->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => SavingsProduct::factory()->create(['company_id' => $this->branch->company_id])->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);
});

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function savingsOp(string $type, array $payload, ?string $opId = null): array
{
    return [
        'op_id' => $opId ?? (string) Str::uuid(),
        'op_type' => $type,
        'payload' => $payload,
        'recorded_at' => now()->toISOString(),
    ];
}

it('lets an agent raise a withdrawal offline that a manager then approves and pays by its client id', function (): void {
    $withdrawalId = (string) Str::uuid();

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [savingsOp('withdrawal.request', [
            'savings_account_id' => $this->account->id,
            'amount' => 300_00,
            'reason' => 'School fees',
            'client_reference' => $withdrawalId,
        ])],
    ])->assertOk()
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.0.result.withdrawal_request_id', $withdrawalId)
        ->assertJsonPath('results.0.result.status', 'pending');

    $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [
            savingsOp('withdrawal.approve', ['withdrawal_request_id' => $withdrawalId]),
            savingsOp('withdrawal.pay', ['withdrawal_request_id' => $withdrawalId]),
        ],
    ])->assertOk()
        ->assertJsonPath('results.0.result.status', 'approved')
        ->assertJsonPath('results.1.result.status', 'paid');

    expect(WithdrawalRequest::find($withdrawalId)->status)->toBe(WithdrawalStatus::Paid)
        ->and($this->account->refresh()->balance)->toBe(700_00);
});

it('does not create a second withdrawal when the request op is replayed', function (): void {
    $op = savingsOp('withdrawal.request', [
        'savings_account_id' => $this->account->id,
        'amount' => 100_00,
    ]);

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', ['ops' => [$op]])->assertOk();
    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', ['ops' => [$op]])
        ->assertJsonPath('results.0.status', 'duplicate');

    expect(WithdrawalRequest::count())->toBe(1);
});

it('lets a manager reject a withdrawal through the sync batch', function (): void {
    $withdrawalId = (string) Str::uuid();
    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [savingsOp('withdrawal.request', [
            'savings_account_id' => $this->account->id,
            'amount' => 100_00,
            'client_reference' => $withdrawalId,
        ])],
    ]);

    $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [savingsOp('withdrawal.reject', ['withdrawal_request_id' => $withdrawalId, 'reason' => 'Signature mismatch'])],
    ])->assertJsonPath('results.0.result.status', 'rejected');

    expect(WithdrawalRequest::find($withdrawalId)->rejected_reason)->toBe('Signature mismatch');
});

it('rejects withdrawal decisions and payouts from a field agent', function (string $type): void {
    $withdrawal = WithdrawalRequest::factory()->create([
        'company_id' => $this->account->company_id,
        'branch_id' => $this->account->branch_id,
        'savings_account_id' => $this->account->id,
        'customer_id' => $this->customer->id,
        'amount' => 100_00,
    ]);

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [savingsOp($type, ['withdrawal_request_id' => $withdrawal->id, 'reason' => 'x'])],
    ])->assertJsonPath('results.0.status', 'rejected')
        ->assertJsonPath('results.0.result.errors.0', 'This operation is not allowed for your role.');
})->with(['withdrawal.approve', 'withdrawal.reject', 'withdrawal.pay']);

it('keeps a withdrawal decision retryable while its request has not reached the server', function (): void {
    $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [savingsOp('withdrawal.approve', ['withdrawal_request_id' => (string) Str::uuid()])],
    ])->assertJsonPath('results.0.status', 'rejected')
        ->assertJsonPath('results.0.retryable', true);
});

it('lets an agent sell shares offline, idempotently on replay', function (): void {
    $sharesProduct = SavingsProduct::factory()->shares(10_00)->create(['company_id' => $this->branch->company_id]);
    $sharesAccount = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $sharesProduct->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 0,
    ]);
    $op = savingsOp('shares.purchase', ['savings_account_id' => $sharesAccount->id, 'shares' => 5]);

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', ['ops' => [$op]])
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.0.result.share_count', 5)
        ->assertJsonPath('results.0.result.balance', 50_00);
    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', ['ops' => [$op]])
        ->assertJsonPath('results.0.status', 'duplicate');

    expect($sharesAccount->refresh()->share_count)->toBe(5);
});

it('applies a partial customer update from the sync batch, leaving other fields alone', function (): void {
    $lastName = $this->customer->last_name;

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [savingsOp('customer.update', [
            'customer_id' => $this->customer->id,
            'first_name' => 'Abena',
            'occupation' => 'Trader',
            'beneficiaries' => [['name' => 'Kojo Mensah', 'relationship' => 'Son']],
        ])],
    ])->assertJsonPath('results.0.status', 'applied');

    $customer = $this->customer->refresh();
    expect($customer->first_name)->toBe('Abena')
        ->and($customer->last_name)->toBe($lastName)
        ->and($customer->occupation)->toBe('Trader')
        ->and($customer->beneficiaries()->pluck('name')->all())->toBe(['Kojo Mensah']);
});

it('rejects a customer update that blanks a required field', function (): void {
    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [savingsOp('customer.update', ['customer_id' => $this->customer->id, 'first_name' => null])],
    ])->assertJsonPath('results.0.status', 'rejected');
});

it('lets a manager update a customer over REST', function (): void {
    $this->actingAs($this->manager, 'sanctum')
        ->patchJson("/api/v1/customers/{$this->customer->id}", ['phone' => '+233200000001'])
        ->assertOk()
        ->assertJsonPath('data.phone', '+233200000001');
});
