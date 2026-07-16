<?php

use App\Actions\Customers\ProvisionCustomerLoginAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->companyAdmin = User::factory()->companyAdmin($this->branch->company)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
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

it('serves the agent dashboard with today stats and a 30-day trend', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $response = $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/dashboard/agent');

    $response->assertOk()
        ->assertJsonPath('today.collections_total', 500)
        ->assertJsonPath('today.collections_count', 1)
        ->assertJsonPath('today.expected_cash', 500)
        ->assertJsonPath('accounts.active_count', 1)
        ->assertJsonCount(30, 'trend');

    expect(collect($response->json('trend'))->last()['total'])->toBe(500);
});

it('serves the branch dashboard to a manager', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $response = $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/dashboard/branch');

    $response->assertOk()
        ->assertJsonPath('stats.collections_today', 500)
        ->assertJsonPath('stats.active_accounts', 1)
        ->assertJsonPath('stats.cash_in_field', 500)
        ->assertJsonCount(30, 'collections_trend')
        ->assertJsonCount(30, 'savings_vs_withdrawals')
        ->assertJsonStructure(['loan_status_breakdown']);
});

it('lets a company admin pick a branch but not a foreign one', function (): void {
    $this->actingAs($this->companyAdmin, 'sanctum')
        ->getJson('/api/v1/dashboard/branch?branch_id='.$this->branch->id)
        ->assertOk();

    $otherBranch = Branch::factory()->create();

    $this->actingAs($this->companyAdmin, 'sanctum')
        ->getJson('/api/v1/dashboard/branch?branch_id='.$otherBranch->id)
        ->assertNotFound();
});

it('denies a field agent the branch dashboard', function (): void {
    $this->actingAs($this->agent, 'sanctum')
        ->getJson('/api/v1/dashboard/branch')
        ->assertForbidden();
});

it('serves the customer dashboard with accounts and balance trend', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);
    app(RecordCollectionAction::class)->execute($this->agent, $this->account->fresh(), 500);

    $customerUser = app(ProvisionCustomerLoginAction::class)->execute($this->customer->fresh(), 'secret-password');

    $response = $this->actingAs($customerUser, 'sanctum')->getJson('/api/v1/dashboard/customer');

    $expectedBalance = (int) $this->account->fresh()->balance;

    $response->assertOk()
        ->assertJsonPath('totals.balance', $expectedBalance)
        ->assertJsonCount(1, 'accounts')
        ->assertJsonPath('loans.active_count', 0)
        ->assertJsonCount(30, 'balance_trend');

    expect(collect($response->json('balance_trend'))->last()['balance'])->toBe($expectedBalance);
});

it('denies a customer the agent dashboard', function (): void {
    $customerUser = app(ProvisionCustomerLoginAction::class)->execute($this->customer, 'secret-password');

    $this->actingAs($customerUser, 'sanctum')
        ->getJson('/api/v1/dashboard/agent')
        ->assertForbidden();
});
