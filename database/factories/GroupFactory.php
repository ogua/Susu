<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::find($attributes['branch_id'])->company_id,
            'name' => 'Susu Group '.fake()->unique()->numberBetween(1, 999),
            'code' => strtoupper(fake()->unique()->bothify('GRP-###')),
            'contribution_amount' => 1000, // GHS 10.00 per round
            'frequency' => 'monthly',
            'status' => 'draft',
        ];
    }
}
