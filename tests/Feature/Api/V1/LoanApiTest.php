<?php

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->customer = Customer::factory()->forBranch($this->branch)->create();

    $this->loanProduct = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'min_amount' => 100_00,
        'max_amount' => 1_000_00,
    ]);
});

it('lets an agent apply for a loan on behalf of a customer', function (): void {
    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/loans', [
        'customer_id' => $this->customer->id,
        'loan_product_id' => $this->loanProduct->id,
        'amount' => 300_00,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'applied')
        ->assertJsonPath('data.customer_id', $this->customer->id);
});

it('lets a customer self-apply for a loan without specifying customer_id', function (): void {
    $customerUser = User::factory()->customerUser()->create(['company_id' => $this->branch->company_id]);
    $this->customer->update(['user_id' => $customerUser->id]);

    $response = $this->actingAs($customerUser, 'sanctum')->postJson('/api/v1/loans', [
        'loan_product_id' => $this->loanProduct->id,
        'amount' => 300_00,
    ]);

    $response->assertCreated()->assertJsonPath('data.customer_id', $this->customer->id);
});

it('rejects an amount outside the product min/max', function (): void {
    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/loans', [
        'customer_id' => $this->customer->id,
        'loan_product_id' => $this->loanProduct->id,
        'amount' => 5_000_00,
    ])->assertUnprocessable();
});

it('reports loan eligibility for a savings account', function (): void {
    $product = SavingsProduct::factory()->create(['company_id' => $this->branch->company_id]);
    $account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $product->id,
        'opened_at' => now()->subDays(5), // too new
    ]);

    $response = $this->actingAs($this->agent, 'sanctum')->getJson(
        "/api/v1/loans/eligibility?savings_account_id={$account->id}&amount=30000"
    );

    $response->assertOk()->assertJsonPath('eligible', false);
    expect($response->json('reasons'))->not->toBeEmpty();
});

it('lists active loan products for the company', function (): void {
    LoanProduct::factory()->create(['company_id' => $this->branch->company_id, 'is_active' => false]);

    $response = $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/loans/products');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($this->loanProduct->id);
});

it('only shows a customer their own loans, scoped to their company', function (): void {
    $customerUser = User::factory()->customerUser()->create(['company_id' => $this->branch->company_id]);
    $this->customer->update(['user_id' => $customerUser->id]);

    $ownLoan = app(ApplyForLoanAction::class)->execute($this->agent, $this->customer, $this->loanProduct, 300_00);
    $otherCustomer = Customer::factory()->forBranch($this->branch)->create();
    app(ApplyForLoanAction::class)->execute($this->agent, $otherCustomer, $this->loanProduct, 300_00);

    $response = $this->actingAs($customerUser, 'sanctum')->getJson('/api/v1/loans');

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($ownLoan->id)->and($ids)->toHaveCount(1);
});

it('lets an agent record a cash repayment via the direct endpoint', function (): void {
    $loan = app(ApplyForLoanAction::class)->execute($this->agent, $this->customer, $this->loanProduct, 300_00);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    $response = $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/loans/{$disbursed->id}/repayments", [
        'amount' => 50_00,
    ]);

    $response->assertCreated()
        ->assertJsonPath('loan.outstanding_balance', $disbursed->outstanding_balance - 50_00);
});

it('denies a customer from recording a repayment', function (): void {
    $customerUser = User::factory()->customerUser()->create(['company_id' => $this->branch->company_id]);
    $this->customer->update(['user_id' => $customerUser->id]);

    $loan = app(ApplyForLoanAction::class)->execute($this->agent, $this->customer, $this->loanProduct, 300_00);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    $this->actingAs($customerUser, 'sanctum')
        ->postJson("/api/v1/loans/{$disbursed->id}/repayments", ['amount' => 50_00])
        ->assertForbidden();
});

it('applies an offline loan application and repayment through sync/batch', function (): void {
    $loanRef = (string) Str::uuid();

    $applyOp = [
        'op_id' => (string) Str::uuid(),
        'op_type' => 'loan.apply',
        'payload' => [
            'customer_id' => $this->customer->id,
            'loan_product_id' => $this->loanProduct->id,
            'amount' => 300_00,
            'client_reference' => $loanRef,
        ],
        'recorded_at' => now()->toISOString(),
    ];

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', ['ops' => [$applyOp]]);

    $response->assertOk()->assertJsonPath('results.0.status', 'applied');
    $loanId = $response->json('results.0.result.loan_id');

    $loan = Loan::where('client_reference', $loanRef)->findOrFail($loanId);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    $repayOp = [
        'op_id' => (string) Str::uuid(),
        'op_type' => 'loan.repayment.record',
        'payload' => ['loan_id' => $disbursed->id, 'amount' => 50_00],
        'recorded_at' => now()->toISOString(),
    ];

    $repayResponse = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', ['ops' => [$repayOp]]);

    $repayResponse->assertOk()->assertJsonPath('results.0.status', 'applied');
    expect($disbursed->fresh()->outstanding_balance)->toBe($disbursed->outstanding_balance - 50_00);
});
