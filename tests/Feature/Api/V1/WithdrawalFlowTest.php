<?php

use App\Actions\Customers\ProvisionCustomerLoginAction;
use App\Actions\Savings\DecideWithdrawalAction;
use App\Actions\Savings\PayWithdrawalAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Actions\Savings\RequestWithdrawalAction;
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

it('returns the original request when a withdrawal is retried with the same client_reference', function (): void {
    $payload = [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
        'client_reference' => '6f1c2e0a-9b7d-4c3e-8a21-5d4f3b2a1c0e',
    ];

    $first = $this->actingAs($this->customerUser, 'sanctum')
        ->postJson('/api/v1/customer/withdrawal-requests', $payload)
        ->assertCreated();

    $this->actingAs($this->customerUser, 'sanctum')
        ->postJson('/api/v1/customer/withdrawal-requests', $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(WithdrawalRequest::where('savings_account_id', $this->account->id)->count())->toBe(1);
});

it('rejects a client_reference already used for a different account', function (): void {
    $reference = '0a9b8c7d-6e5f-4a3b-9c2d-1e0f9a8b7c6d';

    $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/v1/customer/withdrawal-requests', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
        'client_reference' => $reference,
    ])->assertCreated();

    $otherAccount = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
    ]);

    $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/v1/customer/withdrawal-requests', [
        'savings_account_id' => $otherAccount->id,
        'amount' => 100,
        'client_reference' => $reference,
    ])->assertUnprocessable()->assertJsonValidationErrors('client_reference');
});

it('labels each account transaction with its direction for the customer', function (): void {
    $request = app(RequestWithdrawalAction::class)
        ->execute($this->customerUser, $this->account, 500);
    app(DecideWithdrawalAction::class)->approve($this->manager, $request);
    app(PayWithdrawalAction::class)->execute($this->manager, $request->refresh());

    $transactions = collect(
        $this->actingAs($this->customerUser, 'sanctum')
            ->getJson("/api/v1/customer/accounts/{$this->account->id}/transactions")
            ->assertOk()
            ->json('data')
    );

    $directions = $transactions->groupBy('type')->map(fn ($rows) => $rows->pluck('direction')->unique()->values()->all());

    expect($directions['collection'])->toBe(['credit'])
        ->and($directions['commission'])->toBe(['debit'])
        ->and($directions['withdrawal'])->toBe(['debit']);
});

it('pays an approved request only once even when paid twice', function (): void {
    $request = app(RequestWithdrawalAction::class)->execute($this->agent, $this->account, 500);
    app(DecideWithdrawalAction::class)->approve($this->manager, $request);
    $balanceBefore = $this->account->refresh()->balance;

    $stale = WithdrawalRequest::find($request->id);
    app(PayWithdrawalAction::class)->execute($this->manager, $request->refresh());

    expect(fn () => app(PayWithdrawalAction::class)->execute($this->manager, $stale))
        ->toThrow(ValidationException::class);
    expect($this->account->refresh()->balance)->toBe($balanceBefore - 500);
});

it('stops a manager approving a withdrawal they requested', function (): void {
    $request = app(RequestWithdrawalAction::class)->execute($this->manager, $this->account, 500);

    expect(fn () => app(DecideWithdrawalAction::class)->approve($this->manager, $request))
        ->toThrow(ValidationException::class, 'You cannot approve a withdrawal you requested.');
    expect($request->refresh()->status)->toBe(WithdrawalStatus::Pending);
});

it('lets a company admin approve a withdrawal they requested', function (): void {
    $admin = User::factory()->companyAdmin($this->branch->company)->create();
    $request = app(RequestWithdrawalAction::class)->execute($admin, $this->account, 500);

    app(DecideWithdrawalAction::class)->approve($admin, $request);

    expect($request->refresh()->status)->toBe(WithdrawalStatus::Approved);
});
