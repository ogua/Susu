<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\LoanGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanGroup>
 */
class LoanGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::find($attributes['branch_id'])->company_id,
            'name' => 'Loan Group '.fake()->unique()->numberBetween(1, 999),
            'code' => strtoupper(fake()->unique()->bothify('LGRP-###')),
            'is_active' => true,
        ];
    }
}
