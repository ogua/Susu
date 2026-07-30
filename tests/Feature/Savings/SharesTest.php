<?php

use App\Actions\Savings\BuySharesAction;
use App\Actions\Savings\OpenSavingsAccountAction;
use App\Actions\Savings\RequestWithdrawalAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->customer = Customer::factory()->forBranch($this->branch)->create();

    $this->product = SavingsProduct::factory()->shares(10_00)->create([
        'company_id' => $this->branch->company_id,
    ]);
});

it('opens a shares account with zero balance and zero shares', function (): void {
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
    );

    expect($account->balance)->toBe(0)
        ->and($account->share_count)->toBe(0)
        ->and($account->target_amount)->toBeNull()
        ->and($account->matures_at)->toBeNull();
});

it('buys shares, incrementing share_count and balance by shares times par value', function (): void {
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
    );

    $result = app(BuySharesAction::class)->execute($this->agent, $account, 50);

    expect($result->duplicate)->toBeFalse()
        ->and($result->account->share_count)->toBe(50)
        ->and($result->account->balance)->toBe(500_00); // 50 shares * 10_00 par value

    // Debiting the agent's cash-in-hand (an Asset, debit-normal) increases
    // it — the same direction RecordCollectionAction uses for a deposit.
    $agentCash = app(ChartOfAccounts::class)->agentCash($this->agent);
    expect($agentCash->refresh()->balance)->toBe(500_00)
        ->and($result->account->ledgerAccount->refresh()->balance)->toBe(500_00);
});

it('rejects a zero or negative share purchase', function (): void {
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
    );

    expect(fn () => app(BuySharesAction::class)->execute($this->agent, $account, 0))
        ->toThrow(ValidationException::class);
});

it('rejects a share purchase on a non-shares account', function (): void {
    $dailyProduct = SavingsProduct::factory()->create(['company_id' => $this->branch->company_id]);
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $dailyProduct,
        agent: $this->agent,
    );

    expect(fn () => app(BuySharesAction::class)->execute($this->agent, $account, 10))
        ->toThrow(ValidationException::class);
});

it('is idempotent when the same client_reference is replayed', function (): void {
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
    );

    $ref = (string) Str::uuid();
    $action = app(BuySharesAction::class);

    $first = $action->execute($this->agent, $account, 20, clientReference: $ref);
    $second = $action->execute($this->agent, $account->fresh(), 20, clientReference: $ref);

    expect($second->duplicate)->toBeTrue()
        ->and($second->entry->id)->toBe($first->entry->id)
        ->and($account->fresh()->share_count)->toBe(20); // only applied once
});

it('charges no early-withdrawal penalty on a shares account regardless of product config', function (): void {
    $this->product->update(['early_withdrawal_penalty_bps' => 1000]);

    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product->fresh(),
        agent: $this->agent,
    );
    app(BuySharesAction::class)->execute($this->agent, $account, 10);

    $request = app(RequestWithdrawalAction::class)->execute($this->manager, $account->fresh(), 100_00);

    expect($request->penalty_amount)->toBe(0);
});
