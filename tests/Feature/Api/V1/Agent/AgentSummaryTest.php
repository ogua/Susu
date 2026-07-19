<?php

use App\Actions\Agents\ReconcileAgentDayAction;
use App\Actions\Agents\RecordAgentRemittanceAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\AgentSummaryStatus;
use App\Models\AgentDailySummary;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();

    $this->product = SavingsProduct::factory()->firstContributionCommission()->create([
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

it('reports expected cash equal to the agent cash ledger balance', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $response = $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/agent/summary/today');

    $response->assertOk()->assertJsonPath('cash_in_hand', 1000);
});

it('submits a day sheet and records variance against declared cash', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/summaries', [
        'declared_cash' => 400,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.expected_cash', 500)
        ->assertJsonPath('data.declared_cash', 400)
        ->assertJsonPath('data.variance', -100)
        ->assertJsonPath('data.status', AgentSummaryStatus::Submitted->value);
});

it('reduces agent cash-in-hand when cash is remitted to the branch', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    app(RecordAgentRemittanceAction::class)->execute($this->agent, 1000);

    $response = $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/agent/summary/today');
    $response->assertOk()->assertJsonPath('cash_in_hand', 0);
});

it('lets a branch manager reconcile a submitted day sheet', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/summaries', ['declared_cash' => 500]);

    $summary = $this->agent->fresh()->id;
    $record = AgentDailySummary::where('agent_id', $this->agent->id)->first();

    app(ReconcileAgentDayAction::class)->execute($this->manager, $record);

    expect($record->refresh()->status)->toBe(AgentSummaryStatus::Reconciled);
});
