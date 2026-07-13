<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\LoanProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanProduct>
 */
class LoanProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => 'Susu Boost Loan',
            'code' => strtoupper(fake()->unique()->bothify('LN-###')),
            'interest_method' => 'flat',
            'interest_rate_bps' => 300, // 3% per period, flat
            'term_period_count' => 6,
            'repayment_frequency' => 'monthly',
            'origination_fee_amount' => 0,
            'penalty_rate_bps' => 500, // 5% of the overdue installment
            'grace_period_days' => 3,
            'min_amount' => 50_00,
            'max_amount' => 5_000_00,
            'is_active' => true,
        ];
    }
}
