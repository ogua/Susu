<?php

namespace Database\Factories;

use App\Models\LoanCollateral;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanCollateral>
 */
class LoanCollateralFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'type' => fake()->randomElement(['Vehicle', 'Land', 'Equipment', 'Household items']),
            'description' => fake()->sentence(4),
            'estimated_value' => fake()->numberBetween(500, 20_000) * 100,
            'serial_number' => strtoupper(fake()->bothify('SN-#####')),
        ];
    }
}
