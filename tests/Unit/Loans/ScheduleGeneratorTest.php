<?php

use App\Enums\InterestMethod;
use App\Enums\LoanFrequency;
use App\Services\Loans\InterestCalculator;
use App\Services\Loans\ScheduleGenerator;
use Illuminate\Support\Carbon;

function generator(): ScheduleGenerator
{
    return new ScheduleGenerator(new InterestCalculator);
}

it('splits principal evenly across periods with the rounding remainder on the last installment', function (): void {
    // 1000 / 3 = 333.33..., so periods are 333, 333, 334 — sums to exactly 1000.
    $schedule = generator()->generate(1_000, 0, 3, InterestMethod::Flat, LoanFrequency::Monthly, Carbon::parse('2026-01-01'));

    expect($schedule)->toHaveCount(3)
        ->and($schedule[0]->principalDue)->toBe(333)
        ->and($schedule[1]->principalDue)->toBe(333)
        ->and($schedule[2]->principalDue)->toBe(334)
        ->and(array_sum(array_map(fn ($i) => $i->principalDue, $schedule)))->toBe(1_000);
});

it('charges identical flat interest every period regardless of the declining balance', function (): void {
    $schedule = generator()->generate(60_000, 300, 6, InterestMethod::Flat, LoanFrequency::Monthly, Carbon::parse('2026-01-01'));

    foreach ($schedule as $installment) {
        expect($installment->interestDue)->toBe(1_800);
    }
});

it('charges declining interest each period under reducing balance', function (): void {
    $schedule = generator()->generate(60_000, 300, 6, InterestMethod::ReducingBalance, LoanFrequency::Monthly, Carbon::parse('2026-01-01'));

    // Principal per period: 10,000. Balances before each period: 60k,50k,40k,30k,20k,10k.
    expect($schedule[0]->interestDue)->toBe(1_800) // 60,000 * 3%
        ->and($schedule[1]->interestDue)->toBe(1_500) // 50,000 * 3%
        ->and($schedule[5]->interestDue)->toBe(300); // 10,000 * 3%

    // Reducing balance is strictly non-increasing across the schedule.
    for ($i = 1; $i < count($schedule); $i++) {
        expect($schedule[$i]->interestDue)->toBeLessThanOrEqual($schedule[$i - 1]->interestDue);
    }
});

it('advances due dates monthly', function (): void {
    $schedule = generator()->generate(300, 0, 3, InterestMethod::Flat, LoanFrequency::Monthly, Carbon::parse('2026-01-15'));

    expect($schedule[0]->dueDate->toDateString())->toBe('2026-02-15')
        ->and($schedule[1]->dueDate->toDateString())->toBe('2026-03-15')
        ->and($schedule[2]->dueDate->toDateString())->toBe('2026-04-15');
});

it('advances due dates weekly', function (): void {
    $schedule = generator()->generate(300, 0, 3, InterestMethod::Flat, LoanFrequency::Weekly, Carbon::parse('2026-01-01'));

    expect($schedule[0]->dueDate->toDateString())->toBe('2026-01-08')
        ->and($schedule[1]->dueDate->toDateString())->toBe('2026-01-15')
        ->and($schedule[2]->dueDate->toDateString())->toBe('2026-01-22');
});

it('sums total interest across the schedule via the convenience method', function (): void {
    $total = generator()->totalInterest(60_000, 300, 6, InterestMethod::Flat, LoanFrequency::Monthly, Carbon::parse('2026-01-01'));

    expect($total)->toBe(1_800 * 6);
});
