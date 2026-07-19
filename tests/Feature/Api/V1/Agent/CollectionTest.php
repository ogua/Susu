<?php

use App\Enums\AccountStatus;
use App\Models\AgentDailySummary;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $this->product = SavingsProduct::factory()->firstContributionCommission()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
        'cycle_length_days' => 31,
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

it('records a collection: agent-cash debited, customer-liability credited', function (): void {
    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/collections', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
    ]);

    $response->assertCreated()->assertJsonPath('balance', 0)->assertJsonPath('commission', 500);

    $agentCash = app(ChartOfAccounts::class)->agentCash($this->agent->fresh());
    expect($agentCash->refresh()->balance)->toBe(500)
        ->and($this->account->refresh()->balance)->toBe(0) // deposit 500, commission 500 (day-1 fee)
        ->and($this->account->contributions_this_cycle)->toBe(1);
});

it('charges no commission mid-cycle', function (): void {
    $this->account->update(['contributions_this_cycle' => 5]);

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/collections', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
    ]);

    $response->assertCreated()->assertJsonPath('commission', 0)->assertJsonPath('balance', 500);
});

it('rolls the account into a new cycle after cycle_length_days contributions', function (): void {
    $this->account->update(['contributions_this_cycle' => 30, 'cycle_number' => 1]);

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/collections', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
    ])->assertCreated();

    expect($this->account->refresh())
        ->contributions_this_cycle->toBe(0)
        ->cycle_number->toBe(2);
});

it('denies an agent from another branch', function (): void {
    $otherBranch = Branch::factory()->create(['company_id' => $this->branch->company_id]);
    $otherAgent = User::factory()->fieldAgent($otherBranch)->create();

    $this->actingAs($otherAgent, 'sanctum')->postJson('/api/v1/agent/collections', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
    ])->assertUnprocessable();
});

it('rejects amounts that are not a multiple of the contribution', function (): void {
    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/collections', [
        'savings_account_id' => $this->account->id,
        'amount' => 300,
    ])->assertUnprocessable();
});

it('is idempotent when the same client_reference is replayed', function (): void {
    $ref = (string) Str::uuid();

    $first = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/collections', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
        'client_reference' => $ref,
    ])->assertCreated();

    $second = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/collections', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
        'client_reference' => $ref,
    ])->assertOk();

    expect($second->json('entry.id'))->toBe($first->json('entry.id'))
        ->and($this->account->refresh()->balance)->toBe(0); // only posted once
});

it('queues exactly one customer notification per payment', function (): void {
    Notification::fake();

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/collections', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
    ])->assertCreated();

    expect(NotificationLog::where('customer_id', $this->customer->id)->count())->toBe(1);
});

it('reuses the same day summary row across multiple collections without a duplicate-key error', function (): void {
    // Simulates the row already having been created by another request for
    // this agent/day (the scenario that used to trip the unique constraint
    // when firstOrCreate's internal race-retry couldn't see it).
    AgentDailySummary::query()->insertOrIgnore([[
        'id' => (string) Str::uuid7(),
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'agent_id' => $this->agent->id,
        'summary_date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]]);

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/collections', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
    ])->assertCreated();

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/collections', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
    ])->assertCreated();

    $summary = AgentDailySummary::where('agent_id', $this->agent->id)
        ->where('summary_date', now()->toDateString())
        ->firstOrFail();

    expect($summary->collections_count)->toBe(2)
        ->and($summary->collections_total)->toBe(1000);
});

it('reactivates a dormant account on collection', function (): void {
    $this->account->update(['status' => AccountStatus::Dormant]);

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/collections', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
    ])->assertCreated();

    expect($this->account->refresh()->status)->toBe(AccountStatus::Active);
});
