<?php

namespace Database\Factories;

use App\Models\GroupLoan;
use App\Models\GroupLoanDeposit;
use App\Models\SavingsAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupLoanDeposit>
 */
class GroupLoanDepositFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_loan_id' => GroupLoan::factory(),
            'savings_account_id' => SavingsAccount::factory(),
            'amount' => 100_00,
            'type' => 'held',
            'recorded_at' => now(),
        ];
    }
}
