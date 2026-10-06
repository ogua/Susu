<?php

use App\Actions\Savings\MatureFixedDepositAction;
use App\Actions\Savings\OpenSavingsAccountAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Actions\Savings\RequestWithdrawalAction;
use App\Console\Commands\MatureFixedDeposits;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->customer = Customer::factory()->forBranch($this->branch)->create();

    $this->product = SavingsProduct::factory()->fixedDeposit(1200)->create([
        'company_id' => $this->branch->company_id,
    ]);
});

it('requires a maturity date to open a fixed deposit account', function (): void {
    app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
    );
})->throws(ValidationException::class);

it('opens a fixed deposit account, funds the principal via one collection, and snapshots the products rate', function (): void {
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
        contributionAmount: 5_000_00,
        maturesAt: now()->addMonths(6),
    );

    expect($account->interest_rate_bps)->toBe(1200)
        ->and($account->matures_at)->not->toBeNull()
        ->and($account->matured_at)->toBeNull()
        ->and($account->balance)->toBe(0);

    app(RecordCollectionAction::class)->execute($this->agent, $account, 5_000_00);

    expect($account->fresh()->balance)->toBe(5_000_00);
});

it('blocks withdrawal before the fixed deposit matures', function (): void {
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
        contributionAmount: 1_000_00,
        maturesAt: now()->addMonths(3),
    );
    app(RecordCollectionAction::class)->execute($this->agent, $account, 1_000_00);

    expect(fn () => app(RequestWithdrawalAction::class)->execute($this->manager, $account->fresh(), 1_000_00))
        ->toThrow(ValidationException::class);
});

it('matures a fixed deposit, crediting prorated interest into the balance and recognizing a bad-debt-style expense', function (): void {
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
        contributionAmount: 5_000_00,
        maturesAt: now()->subDay(),
    );
    app(RecordCollectionAction::class)->execute($this->agent, $account, 5_000_00);
    $account->refresh()->forceFill([
        'opened_at' => '2026-01-01 00:00:00',
        'cycle_started_at' => '2026-01-01',
        'matures_at' => '2026-07-01',
    ])->save();

    // intdiv(500000 * 1200 * 181, 10000 * 365) = 29753 pesewas.
    $expectedInterest = 29_753;

    $matured = app(MatureFixedDepositAction::class)->execute($account->fresh());

    expect($matured->matured_at)->not->toBeNull()
        ->and($matured->balance)->toBe(5_000_00 + $expectedInterest);

    $savingsInterestExpense = app(ChartOfAccounts::class)->savingsInterestExpense($this->branch->company);
    expect($savingsInterestExpense->refresh()->balance)->toBe($expectedInterest)
        ->and($matured->ledgerAccount->refresh()->balance)->toBe(5_000_00 + $expectedInterest);

    // Idempotent re-run posts nothing more.
    $reRun = app(MatureFixedDepositAction::class)->execute($matured->fresh());
    expect($reRun->balance)->toBe($matured->balance)
        ->and($savingsInterestExpense->refresh()->balance)->toBe($expectedInterest);

    // Post-maturity withdrawal is a completely ordinary, zero-penalty flow.
    $request = app(RequestWithdrawalAction::class)->execute($this->manager, $matured->fresh(), $matured->balance);
    expect($request->penalty_amount)->toBe(0);
});

it('earns interest only from the day the principal was deposited, not from opening', function (): void {
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
        contributionAmount: 5_000_00,
        maturesAt: now()->subDay(),
    );
    app(RecordCollectionAction::class)->execute($this->agent, $account, 5_000_00);
    $account->refresh()->forceFill([
        'opened_at' => '2026-01-01 00:00:00',
        'cycle_started_at' => '2026-03-01',
        'matures_at' => '2026-07-01',
    ])->save();

    $matured = app(MatureFixedDepositAction::class)->execute($account->fresh());

    // 122 days funded: intdiv(500000 * 1200 * 122, 10000 * 365) = 20054 pesewas.
    expect($matured->balance)->toBe(5_000_00 + 20_054);
});

it('does not let a later product rate change affect an already-open fixed deposit account', function (): void {
    $accountOne = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
        contributionAmount: 1_000_00,
        maturesAt: now()->addMonths(6),
    );

    $this->product->update(['interest_rate_bps' => 500]);

    $customerTwo = Customer::factory()->forBranch($this->branch)->create();
    $accountTwo = app(OpenSavingsAccountAction::class)->execute(
        customer: $customerTwo,
        product: $this->product->fresh(),
        agent: $this->agent,
        contributionAmount: 1_000_00,
        maturesAt: now()->addMonths(6),
    );

    expect($accountOne->interest_rate_bps)->toBe(1200)
        ->and($accountTwo->interest_rate_bps)->toBe(500);
});

it('matures accounts whose maturity date has passed and notifies the customer once', function (): void {
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
        contributionAmount: 1_000_00,
        maturesAt: now()->subDay(),
    );
    // The collection itself fires its own customer-payment notification —
    // capture the baseline so the maturity notification's own delta is
    // asserted in isolation.
    app(RecordCollectionAction::class)->execute($this->agent, $account, 1_000_00);
    $baseline = NotificationLog::where('customer_id', $this->customer->id)->count();

    $this->artisan(MatureFixedDeposits::class)->assertExitCode(0);

    expect($account->fresh()->matured_at)->not->toBeNull()
        ->and(NotificationLog::where('customer_id', $this->customer->id)->count())->toBe($baseline + 1);

    // Idempotent: a second run must not re-notify.
    $this->artisan(MatureFixedDeposits::class)->assertExitCode(0);
    expect(NotificationLog::where('customer_id', $this->customer->id)->count())->toBe($baseline + 1);
});

it('leaves non-fixed-deposit accounts alone', function (): void {
    $dailyProduct = SavingsProduct::factory()->create(['company_id' => $this->branch->company_id]);
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $dailyProduct,
        agent: $this->agent,
    );

    $this->artisan(MatureFixedDeposits::class)->assertExitCode(0);
    expect($account->fresh()->matured_at)->toBeNull();
});
