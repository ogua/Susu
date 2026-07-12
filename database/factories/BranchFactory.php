<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city().' Branch';

        return [
            'company_id' => Company::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'address' => fake()->address(),
            'contact_phone' => '+2332'.fake()->unique()->numerify('########'),
            'contact_email' => fake()->unique()->companyEmail(),
        ];
    }
}
