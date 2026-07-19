<?php

namespace Database\Factories;

use App\Models\GroupLoan;
use App\Models\GroupLoanBorrower;
use App\Models\LoanGroupMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupLoanBorrower>
 */
class GroupLoanBorrowerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_loan_id' => GroupLoan::factory(),
            'loan_group_member_id' => fn (array $attributes) => LoanGroupMember::factory()->create([
                'loan_group_id' => GroupLoan::find($attributes['group_loan_id'])->loan_group_id,
            ])->id,
            'customer_id' => fn (array $attributes) => LoanGroupMember::find($attributes['loan_group_member_id'])->customer_id,
            'share_principal' => 500_00,
            'share_outstanding' => 500_00,
        ];
    }
}
