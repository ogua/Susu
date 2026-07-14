<?php

use App\Actions\Savings\RecordCollectionAction;
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

    $this->product = SavingsProduct::factory()->create(['company_id' => $this->branch->company_id]);
    $this->customer = Customer::factory()->forBranch($this->branch)->create();

    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);

    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);
});

it('lets a branch manager download the statement from the admin panel', function (): void {
    $this->actingAs($this->manager)
        ->get(route('savings-accounts.statement', $this->account))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('denies a field agent from another branch', function (): void {
    $otherBranch = Branch::factory()->create();
    $otherAgent = User::factory()->fieldAgent($otherBranch)->create();

    $this->actingAs($otherAgent)
        ->get(route('savings-accounts.statement', $this->account))
        ->assertForbidden();
});
