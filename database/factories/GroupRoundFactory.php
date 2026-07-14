<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupRound>
 */
class GroupRoundFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'payout_member_id' => fn (array $attributes) => GroupMember::factory()->create([
                'group_id' => $attributes['group_id'],
            ])->id,
            'round_number' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'total_expected' => fn (array $attributes) => Group::find($attributes['group_id'])->contribution_amount,
            'total_collected' => 0,
            'status' => 'pending',
        ];
    }
}
