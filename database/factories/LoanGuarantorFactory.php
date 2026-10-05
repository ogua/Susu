<?php

namespace Database\Factories;

use App\Models\LoanGuarantor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanGuarantor>
 */
class LoanGuarantorFactory extends Factory
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
            'name' => fake()->name(),
            'phone' => '+23324'.fake()->numerify('#######'),
            'relationship' => fake()->randomElement(['Spouse', 'Sibling', 'Friend', 'Colleague']),
            'guaranteed_amount' => fake()->numberBetween(100, 5_000) * 100,
        ];
    }
}
