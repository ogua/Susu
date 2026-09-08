<?php

use App\Enums\LoanFrequency;
use App\Services\Loans\PeriodicScheduleGenerator;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->generator = new PeriodicScheduleGenerator;
});

it('spreads an evenly-divisible principal into equal installments', function (): void {
    $schedule = $this->generator->generate(1000_00, 100_00, LoanFrequency::Weekly, Carbon::parse('2026-09-14'));

    expect($schedule)->toHaveCount(10)
        ->and(collect($schedule)->every(fn ($i): bool => $i->principalDue === 100_00))->toBeTrue()
        ->and(collect($schedule)->sum(fn ($i): int => $i->principalDue))->toBe(1000_00);
});

it('puts the rounding remainder on the last installment', function (): void {
    $schedule = $this->generator->generate(1050_00, 100_00, LoanFrequency::Weekly, Carbon::parse('2026-09-14'));

    expect($schedule)->toHaveCount(11)
        ->and($schedule[10]->principalDue)->toBe(50_00)
        ->and(collect($schedule)->sum(fn ($i): int => $i->principalDue))->toBe(1050_00);
});

it('returns a single installment when the periodic amount covers the whole principal', function (): void {
    $schedule = $this->generator->generate(500_00, 500_00, LoanFrequency::Weekly, Carbon::parse('2026-09-14'));

    expect($schedule)->toHaveCount(1)
        ->and($schedule[0]->principalDue)->toBe(500_00);
});

it('makes the first installment due on the start date and steps by frequency', function (): void {
    $start = Carbon::parse('2026-09-14');

    $weekly = $this->generator->generate(300_00, 100_00, LoanFrequency::Weekly, $start->copy());
    expect($weekly[0]->dueDate->toDateString())->toBe('2026-09-14')
        ->and($weekly[1]->dueDate->toDateString())->toBe('2026-09-21')
        ->and($weekly[2]->dueDate->toDateString())->toBe('2026-09-28');

    $daily = $this->generator->generate(300_00, 100_00, LoanFrequency::Daily, $start->copy());
    expect($daily[1]->dueDate->toDateString())->toBe('2026-09-15');

    $monthly = $this->generator->generate(300_00, 100_00, LoanFrequency::Monthly, $start->copy());
    expect($monthly[1]->dueDate->toDateString())->toBe('2026-10-14');
});

it('rejects a non-positive principal or periodic amount', function (): void {
    expect(fn () => $this->generator->generate(0, 100_00, LoanFrequency::Weekly, Carbon::now()))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->generator->generate(1000_00, 0, LoanFrequency::Weekly, Carbon::now()))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a schedule longer than the cap', function (): void {
    expect(fn () => $this->generator->generate(1_000_000_00, 1_00, LoanFrequency::Daily, Carbon::now()))
        ->toThrow(InvalidArgumentException::class);
});
