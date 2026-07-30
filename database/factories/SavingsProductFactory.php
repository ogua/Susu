<?php

namespace Database\Factories;

use App\Enums\CommissionType;
use App\Enums\SavingsProductType;
use App\Models\Company;
use App\Models\SavingsProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsProduct>
 */
class SavingsProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => 'Daily Susu '.fake()->unique()->numberBetween(1, 999),
            'code' => strtoupper(fake()->unique()->bothify('DS-###')),
            'type' => 'daily_susu',
            'contribution_amount' => 500, // GHS 5.00 per day
            'cycle_length_days' => 31,
            'commission_type' => CommissionType::None,
            'commission_value' => 0,
            'is_active' => true,
        ];
    }

    public function firstContributionCommission(): static
    {
        return $this->state(fn (array $attributes) => [
            'commission_type' => CommissionType::FirstContributionPerCycle,
            'commission_value' => 0,
        ]);
    }

    public function percentageCommission(int $basisPoints): static
    {
        return $this->state(fn (array $attributes) => [
            'commission_type' => CommissionType::Percentage,
            'commission_value' => $basisPoints,
        ]);
    }

    public function percentageOfBalanceCommission(int $basisPoints): static
    {
        return $this->state(fn (array $attributes) => [
            'commission_type' => CommissionType::PercentageOfBalancePerCycle,
            'commission_value' => $basisPoints,
        ]);
    }

    public function flatCommission(int $amountPerCycle): static
    {
        return $this->state(fn (array $attributes) => [
            'commission_type' => CommissionType::FlatPerCycle,
            'commission_value' => $amountPerCycle,
        ]);
    }

    public function target(int $penaltyBps = 1000): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Target Savings '.fake()->unique()->numberBetween(1, 999),
            'type' => SavingsProductType::Target,
            'early_withdrawal_penalty_bps' => $penaltyBps,
        ]);
    }

    public function fixedDeposit(int $rateBps = 1200): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Fixed Deposit '.fake()->unique()->numberBetween(1, 999),
            'type' => SavingsProductType::FixedDeposit,
            'interest_rate_bps' => $rateBps,
        ]);
    }

    public function shares(int $parValue = 10_00): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Share Capital '.fake()->unique()->numberBetween(1, 999),
            'type' => SavingsProductType::Shares,
            'par_value' => $parValue,
        ]);
    }
}
