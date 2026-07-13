<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::find($attributes['branch_id'])->company_id,
            'customer_id' => fn (array $attributes) => Customer::factory()->create([
                'branch_id' => $attributes['branch_id'],
                'company_id' => Branch::find($attributes['branch_id'])->company_id,
            ])->id,
            'loan_product_id' => fn (array $attributes) => LoanProduct::factory()->create([
                'company_id' => Branch::find($attributes['branch_id'])->company_id,
            ])->id,
            'loan_number' => strtoupper(fake()->unique()->bothify('LN-######')),
            'principal_amount' => 500_00,
            'interest_method' => 'flat',
            'interest_rate_bps' => 300,
            'term_period_count' => 6,
            'repayment_frequency' => 'monthly',
            'origination_fee_amount' => 0,
            'penalty_rate_bps' => 500,
            'grace_period_days' => 3,
            'total_interest' => 0,
            'total_repayable' => 0,
            'outstanding_balance' => 0,
            'status' => 'applied',
            'applied_at' => now(),
        ];
    }
}
