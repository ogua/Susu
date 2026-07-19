<?php

namespace Database\Factories;

use App\Models\GroupLoan;
use App\Models\GroupLoanBorrower;
use App\Models\GroupLoanRepayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupLoanRepayment>
 */
class GroupLoanRepaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_loan_id' => GroupLoan::factory(),
            'group_loan_borrower_id' => fn (array $attributes) => GroupLoanBorrower::factory()->create([
                'group_loan_id' => $attributes['group_loan_id'],
            ])->id,
            'amount' => 100_00,
            'recorded_at' => now(),
        ];
    }
}
