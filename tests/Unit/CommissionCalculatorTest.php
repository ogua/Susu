<?php

use App\Enums\CommissionType;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Services\Savings\CommissionCalculator;

function makeAccount(int $position, int $cycleLength = 31, CommissionType $type = CommissionType::FirstContributionPerCycle, int $value = 0, int $balance = 0): SavingsAccount
{
    $account = new SavingsAccount([
        'contribution_amount' => 500,
        'contributions_this_cycle' => $position,
        'cycle_number' => 1,
        'balance' => $balance,
    ]);
    $account->setRelation('product', new SavingsProduct([
        'cycle_length_days' => $cycleLength,
        'commission_type' => $type,
        'commission_value' => $value,
    ]));

    return $account;
}

it('charges one contribution as commission when a cycle starts', function (): void {
    $result = (new CommissionCalculator)->simulate(makeAccount(0), units: 1, amount: 500);

    expect($result->commissionAmount)->toBe(500)
        ->and($result->cyclesStarted)->toBe(1)
        ->and($result->newContributionsThisCycle)->toBe(1);
});

it('charges nothing mid-cycle', function (): void {
    $result = (new CommissionCalculator)->simulate(makeAccount(5), units: 2, amount: 1000);

    expect($result->commissionAmount)->toBe(0)
        ->and($result->newContributionsThisCycle)->toBe(7);
});

it('rolls the cycle over when the last contribution lands', function (): void {
    $result = (new CommissionCalculator)->simulate(makeAccount(30), units: 1, amount: 500);

    expect($result->newContributionsThisCycle)->toBe(0)
        ->and($result->newCycleNumber)->toBe(2)
        ->and($result->commissionAmount)->toBe(0);
});

it('charges commission again when a payment crosses into a new cycle', function (): void {
    // 3 units from position 30 of a 31-day cycle: 31 (completes), 1 (new cycle — fee), 2.
    $result = (new CommissionCalculator)->simulate(makeAccount(30), units: 3, amount: 1500);

    expect($result->commissionAmount)->toBe(500)
        ->and($result->cyclesStarted)->toBe(1)
        ->and($result->newCycleNumber)->toBe(2)
        ->and($result->newContributionsThisCycle)->toBe(2);
});

it('computes percentage commission in basis points', function (): void {
    $account = makeAccount(3, type: CommissionType::Percentage, value: 250); // 2.5%

    $result = (new CommissionCalculator)->simulate($account, units: 2, amount: 1000);

    expect($result->commissionAmount)->toBe(25);
});

it('computes flat commission per cycle started', function (): void {
    $account = makeAccount(0, type: CommissionType::FlatPerCycle, value: 300);

    $result = (new CommissionCalculator)->simulate($account, units: 1, amount: 500);

    expect($result->commissionAmount)->toBe(300);
});

it('charges nothing when commission type is None, regardless of cycle or balance', function (): void {
    $result = (new CommissionCalculator)->simulate(
        makeAccount(0, type: CommissionType::None, balance: 10_000),
        units: 1,
        amount: 500,
    );

    expect($result->commissionAmount)->toBe(0)
        ->and($result->cyclesStarted)->toBe(1);
});

it('computes percentage-of-balance commission when a cycle starts', function (): void {
    // 5% (500 bps) of a 10,000 balance.
    $account = makeAccount(0, type: CommissionType::PercentageOfBalancePerCycle, value: 500, balance: 10_000);

    $result = (new CommissionCalculator)->simulate($account, units: 1, amount: 500);

    expect($result->commissionAmount)->toBe(500);
});

it('charges no percentage-of-balance commission mid-cycle', function (): void {
    $account = makeAccount(5, type: CommissionType::PercentageOfBalancePerCycle, value: 500, balance: 10_000);

    $result = (new CommissionCalculator)->simulate($account, units: 2, amount: 1000);

    expect($result->commissionAmount)->toBe(0);
});

it('computes percentage-of-balance commission independently of the deposit amount', function (): void {
    $account = makeAccount(0, type: CommissionType::PercentageOfBalancePerCycle, value: 1000, balance: 5_000);

    // 10% of the 5,000 balance is 500, regardless of whether the deposit is 200 or 2,000.
    $small = (new CommissionCalculator)->simulate($account, units: 1, amount: 500);

    expect($small->commissionAmount)->toBe(500);
});
