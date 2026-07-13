<?php

use App\Actions\Savings\RecordCollectionAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Loans\EligibilityService;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $this->product = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
    ]);

    $this->customer = Customer::factory()->forBranch($this->branch)->create();
});

it('is eligible with sufficient account age, collection history, and balance', function (): void {
    $account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
        'opened_at' => now()->subDays(90),
    ]);

    $action = app(RecordCollectionAction::class);
    for ($i = 0; $i < 20; $i++) {
        $action->execute($this->agent, $account->refresh(), 500);
    }

    $result = (new EligibilityService)->evaluate($account->refresh(), requestedAmount: 500);

    expect($result->eligible)->toBeTrue()
        ->and($result->reasons)->toBe([]);
});

it('is ineligible when the account is too new', function (): void {
    $account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
        'opened_at' => now()->subDays(10),
    ]);

    $result = (new EligibilityService)->evaluate($account, requestedAmount: 500);

    expect($result->eligible)->toBeFalse()
        ->and($result->reasons)->toContain('Account must be at least 60 days old (currently 10).');
});

it('is ineligible without enough collection history, even on an old account', function (): void {
    $account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
        'opened_at' => now()->subDays(90),
    ]);

    $result = (new EligibilityService)->evaluate($account, requestedAmount: 500);

    expect($result->eligible)->toBeFalse()
        ->and($result->reasons)->toContain('At least 20 prior collections are required (currently 0).');
});

it('is ineligible when the requested amount exceeds the balance multiple', function (): void {
    $account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
        'opened_at' => now()->subDays(90),
        'balance' => 1_000,
    ]);

    // Requesting 10x the balance, far beyond the 3x cap.
    $result = (new EligibilityService)->evaluate($account, requestedAmount: 10_000);

    expect($result->eligible)->toBeFalse()
        ->and($result->reasons)->toContain('Requested amount exceeds 3x the account\'s current balance.');
});

it('is ineligible when the account is closed', function (): void {
    $account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
        'opened_at' => now()->subDays(90),
        'status' => 'closed',
    ]);

    $result = (new EligibilityService)->evaluate($account, requestedAmount: 500);

    expect($result->eligible)->toBeFalse()
        ->and($result->reasons)->toContain('The savings account is closed.');
});
