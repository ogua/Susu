<?php

use App\Enums\InterestMethod;
use App\Services\Loans\InterestCalculator;

it('charges flat interest on the original principal, ignoring the outstanding balance', function (): void {
    $calculator = new InterestCalculator;

    $first = $calculator->periodInterest(outstandingBalance: 60_000, originalPrincipal: 60_000, rateBasisPoints: 300, method: InterestMethod::Flat);
    $later = $calculator->periodInterest(outstandingBalance: 10_000, originalPrincipal: 60_000, rateBasisPoints: 300, method: InterestMethod::Flat);

    expect($first)->toBe(1_800)
        ->and($later)->toBe(1_800); // identical — flat interest never changes
});

it('charges reducing-balance interest on the outstanding balance, not the original principal', function (): void {
    $calculator = new InterestCalculator;

    $early = $calculator->periodInterest(outstandingBalance: 60_000, originalPrincipal: 60_000, rateBasisPoints: 300, method: InterestMethod::ReducingBalance);
    $later = $calculator->periodInterest(outstandingBalance: 30_000, originalPrincipal: 60_000, rateBasisPoints: 300, method: InterestMethod::ReducingBalance);

    expect($early)->toBe(1_800)
        ->and($later)->toBe(900); // half the balance, half the interest
});

it('rounds down to the nearest pesewa', function (): void {
    $calculator = new InterestCalculator;

    // 333 * 300 / 10000 = 9.99 -> truncates to 9, never rounds up and overcharges.
    $interest = $calculator->periodInterest(333, 333, 300, InterestMethod::Flat);

    expect($interest)->toBe(9);
});

it('charges zero interest when the outstanding balance is fully paid down', function (): void {
    $calculator = new InterestCalculator;

    $interest = $calculator->periodInterest(0, 60_000, 300, InterestMethod::ReducingBalance);

    expect($interest)->toBe(0);
});
