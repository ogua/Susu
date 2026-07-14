<?php

namespace Database\Factories;

use App\Models\GroupContribution;
use App\Models\GroupMember;
use App\Models\GroupRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupContribution>
 */
class GroupContributionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_round_id' => GroupRound::factory(),
            'group_member_id' => fn (array $attributes) => GroupMember::factory()->create([
                'group_id' => GroupRound::find($attributes['group_round_id'])->group_id,
            ])->id,
            'amount' => fn (array $attributes) => GroupRound::find($attributes['group_round_id'])->total_expected,
            'recorded_at' => now(),
        ];
    }
}
