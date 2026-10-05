<?php

namespace Database\Factories;

use App\Models\LoanCharge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanCharge>
 */
class LoanChargeFactory extends Factory
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
            'name' => 'Processing fee',
            'amount' => 50_00,
        ];
    }
}
