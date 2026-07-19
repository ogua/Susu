<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\LoanProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupLoan>
 */
class GroupLoanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::find($attributes['branch_id'])->company_id,
            'loan_group_id' => fn (array $attributes) => LoanGroup::factory()->create([
                'branch_id' => $attributes['branch_id'],
                'company_id' => Branch::find($attributes['branch_id'])->company_id,
            ])->id,
            'loan_product_id' => fn (array $attributes) => LoanProduct::factory()->create([
                'company_id' => Branch::find($attributes['branch_id'])->company_id,
            ])->id,
            'loan_number' => strtoupper(fake()->unique()->bothify('GL-######')),
            'principal_amount' => 1000_00,
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
