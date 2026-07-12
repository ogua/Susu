<?php

namespace Database\Factories;

use App\Enums\WithdrawalStatus;
use App\Models\SavingsAccount;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WithdrawalRequest>
 */
class WithdrawalRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'savings_account_id' => SavingsAccount::factory(),
            'company_id' => fn (array $attributes) => SavingsAccount::find($attributes['savings_account_id'])->company_id,
            'branch_id' => fn (array $attributes) => SavingsAccount::find($attributes['savings_account_id'])->branch_id,
            'customer_id' => fn (array $attributes) => SavingsAccount::find($attributes['savings_account_id'])->customer_id,
            'amount' => 1000,
            'reason' => fake()->sentence(),
            'status' => WithdrawalStatus::Pending,
        ];
    }
}
