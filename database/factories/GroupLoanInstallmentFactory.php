<?php

namespace Database\Factories;

use App\Models\GroupLoan;
use App\Models\GroupLoanInstallment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupLoanInstallment>
 */
class GroupLoanInstallmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_loan_id' => GroupLoan::factory(),
            'sequence' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'principal_due' => 166_67,
            'interest_due' => 30_00,
            'penalty_due' => 0,
            'principal_paid' => 0,
            'interest_paid' => 0,
            'penalty_paid' => 0,
            'status' => 'pending',
        ];
    }
}
