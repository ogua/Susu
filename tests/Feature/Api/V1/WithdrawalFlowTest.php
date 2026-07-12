<?php

use App\Actions\Customers\ProvisionCustomerLoginAction;
use App\Actions\Savings\DecideWithdrawalAction;
use App\Actions\Savings\PayWithdrawalAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\WithdrawalStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();

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

    // Fund the account: 3 deposits of 500 past the day-1 commission.
    $collect = app(RecordCollectionAction::class);
    $collect->execute($this->agent, $this->account, 500);
    $collect->execute($this->agent, $this->account, 500);
    $collect->execute($this->agent, $this->account, 500);
    $this->account->refresh();

    $this->customerUser = app(ProvisionCustomerLoginAction::class)->execute($this->customer, 'password');
});

it('lets a customer request a withdrawal within their balance', function (): void {
    $response = $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/v1/customer/withdrawal-requests', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
    ]);

    $response->assertCreated()->assertJsonPath('data.status', WithdrawalStatus::Pending->value);
});

it('rejects a withdrawal above the available balance', function (): void {
    $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/v1/customer/withdrawal-requests', [
        'savings_account_id' => $this->account->id,
        'amount' => $this->account->balance + 10_000,
    ])->assertUnprocessable();
});

it('completes approve then pay, moving cash from branch to customer liability', function (): void {
    $withdrawal = $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/v1/customer/withdrawal-requests', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
    ])->json();

    $request = WithdrawalRequest::find($withdrawal['data']['id']);
    $balanceBefore = $this->account->refresh()->balance;

    app(DecideWithdrawalAction::class)->approve($this->manager, $request);
    app(PayWithdrawalAction::class)->execute($this->manager, $request->refresh());

    expect($request->refresh()->status)->toBe(WithdrawalStatus::Paid)
        ->and($this->account->refresh()->balance)->toBe($balanceBefore - 500);
});

it('cannot pay a request that has not been approved', function (): void {
    $request = WithdrawalRequest::factory()->create([
        'savings_account_id' => $this->account->id,
        'company_id' => $this->account->company_id,
        'branch_id' => $this->account->branch_id,
        'customer_id' => $this->customer->id,
        'amount' => 500,
    ]);

    app(PayWithdrawalAction::class)->execute($this->manager, $request);
})->throws(ValidationException::class);
