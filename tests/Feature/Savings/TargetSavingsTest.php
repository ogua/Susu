<?php

use App\Actions\Savings\OpenSavingsAccountAction;
use App\Actions\Savings\PayWithdrawalAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Actions\Savings\RequestWithdrawalAction;
use App\Console\Commands\MatureTargetSavingsAccounts;
use App\Enums\CommissionType;
use App\Enums\WithdrawalStatus;
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

    $this->product = SavingsProduct::factory()->target(1000)->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
        'commission_type' => CommissionType::FlatPerCycle,
        'commission_value' => 0,
    ]);
});

it('requires a target amount and maturity date to open a target account', function (): void {
    app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
    );
})->throws(ValidationException::class);

it('opens a target account and tracks progress toward the goal', function (): void {
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
        targetAmount: 5_000_00,
        maturesAt: now()->addMonths(6),
    );

    expect($account->target_amount)->toBe(500_000)
        ->and($account->matures_at->toDateString())->toBe(now()->addMonths(6)->toDateString())
        ->and($account->matured_at)->toBeNull()
        ->and($account->targetProgressPercent())->toBe(0.0);

    app(RecordCollectionAction::class)->execute($this->agent, $account, 500);
    app(RecordCollectionAction::class)->execute($this->agent, $account->fresh(), 500);

    expect($account->fresh()->targetProgressPercent())->toBe(0.2); // 1000 / 500000 * 100
});

it('charges an early withdrawal penalty before the target matures', function (): void {
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
        targetAmount: 1_000_00,
        maturesAt: now()->addMonths(3),
    );

    $collect = app(RecordCollectionAction::class);
    $collect->execute($this->agent, $account, 500);
    $collect->execute($this->agent, $account->fresh(), 500);
    $account->refresh();
    expect($account->balance)->toBe(1000);

    $request = app(RequestWithdrawalAction::class)->execute($this->manager, $account, 1000);
    // 10% penalty (1000 bps) of the requested 1000 pesewas = 100 pesewas.
    expect($request->penalty_amount)->toBe(100);

    $request->update(['status' => WithdrawalStatus::Approved, 'approved_by' => $this->manager->id]);
    $paid = app(PayWithdrawalAction::class)->execute($this->manager, $request->fresh());

    // Branch cash starts unfunded in this test (no remittances), so paying
    // out reduces it below zero — the point here is the *delta* (net of
    // penalty) and the penalty income split, not the absolute balance.
    $branchCash = app(ChartOfAccounts::class)->branchCash($this->branch);
    $penaltyIncome = app(ChartOfAccounts::class)->earlyWithdrawalPenaltyIncome($this->branch->company);

    expect($paid->paidEntry->lines()->count())->toBe(3)
        ->and($branchCash->refresh()->balance)->toBe(-900) // -(1000 - 100 penalty)
        ->and($penaltyIncome->refresh()->balance)->toBe(100)
        ->and($account->fresh()->balance)->toBe(0);
});

it('charges no penalty once the account has matured', function (): void {
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
        targetAmount: 1_000_00,
        maturesAt: now()->addDay(),
    );

    $collect = app(RecordCollectionAction::class);
    $collect->execute($this->agent, $account, 500);
    $collect->execute($this->agent, $account->fresh(), 500);
    $account->refresh()->forceFill(['matured_at' => now()])->save();

    $request = app(RequestWithdrawalAction::class)->execute($this->manager, $account->fresh(), 1000);

    expect($request->penalty_amount)->toBe(0);
});

it('matures accounts whose maturity date has passed and notifies the customer once', function (): void {
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $this->product,
        agent: $this->agent,
        targetAmount: 1_000_00,
        maturesAt: now()->subDay(),
    );

    $this->artisan(MatureTargetSavingsAccounts::class)->assertExitCode(0);

    expect($account->fresh()->matured_at)->not->toBeNull()
        ->and(NotificationLog::where('customer_id', $this->customer->id)->count())->toBe(1);

    // Idempotent: a second run must not re-notify.
    $this->artisan(MatureTargetSavingsAccounts::class)->assertExitCode(0);
    expect(NotificationLog::where('customer_id', $this->customer->id)->count())->toBe(1);
});

it('leaves daily susu accounts alone', function (): void {
    $dailyProduct = SavingsProduct::factory()->create(['company_id' => $this->branch->company_id]);
    $account = app(OpenSavingsAccountAction::class)->execute(
        customer: $this->customer,
        product: $dailyProduct,
        agent: $this->agent,
    );

    expect($account->targetProgressPercent())->toBeNull();

    $this->artisan(MatureTargetSavingsAccounts::class)->assertExitCode(0);
    expect($account->fresh()->matured_at)->toBeNull();
});
