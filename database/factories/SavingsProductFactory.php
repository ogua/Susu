<?php

namespace Database\Factories;

use App\Enums\CommissionType;
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
            'commission_type' => CommissionType::FirstContributionPerCycle,
            'commission_value' => 0,
            'is_active' => true,
        ];
    }

    public function percentageCommission(int $basisPoints): static
    {
        return $this->state(fn (array $attributes) => [
            'commission_type' => CommissionType::Percentage,
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
}
