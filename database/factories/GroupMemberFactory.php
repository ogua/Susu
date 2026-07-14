<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupMember>
 */
class GroupMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'customer_id' => fn (array $attributes) => Customer::factory()->create([
                'branch_id' => Group::find($attributes['group_id'])->branch_id,
                'company_id' => Group::find($attributes['group_id'])->company_id,
            ])->id,
            'rotation_position' => fn (array $attributes) => GroupMember::where('group_id', $attributes['group_id'])->count() + 1,
            'status' => 'active',
            'joined_at' => now(),
        ];
    }
}
