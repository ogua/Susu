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

    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $this->customerUser = app(ProvisionCustomerLoginAction::class)->execute($this->customer, 'password');
});

it('lets a customer download their own account statement as a PDF', function (): void {
    $response = $this->actingAs($this->customerUser, 'sanctum')
        ->get("/api/v1/customer/accounts/{$this->account->id}/statement");

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('denies a customer downloading another customer\'s statement', function (): void {
    $otherCustomer = Customer::factory()->forBranch($this->branch)->create();
    $otherUser = app(ProvisionCustomerLoginAction::class)->execute($otherCustomer, 'password');

    $this->actingAs($otherUser, 'sanctum')
        ->get("/api/v1/customer/accounts/{$this->account->id}/statement")
        ->assertNotFound();
});

it('lets the assigned agent download an account statement', function (): void {
    $response = $this->actingAs($this->agent, 'sanctum')
        ->get("/api/v1/agent/accounts/{$this->account->id}/statement");

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
});
