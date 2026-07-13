<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\LoanInstallment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanInstallment>
 */
class LoanInstallmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'sequence' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'principal_due' => 83_34,
            'interest_due' => 15_00,
            'penalty_due' => 0,
            'principal_paid' => 0,
            'interest_paid' => 0,
            'penalty_paid' => 0,
            'status' => 'pending',
        ];
    }
}
