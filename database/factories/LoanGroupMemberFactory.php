<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanGroupMember>
 */
class LoanGroupMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loan_group_id' => LoanGroup::factory(),
            'customer_id' => fn (array $attributes) => Customer::factory()->create([
                'branch_id' => LoanGroup::find($attributes['loan_group_id'])->branch_id,
                'company_id' => LoanGroup::find($attributes['loan_group_id'])->company_id,
            ])->id,
            'status' => 'active',
            'joined_at' => now(),
        ];
    }
}
