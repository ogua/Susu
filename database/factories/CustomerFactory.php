<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => fn (array $attributes) => Branch::find($attributes['branch_id'])->company_id,
            'branch_id' => Branch::factory(),
            'customer_code' => strtoupper(fake()->unique()->bothify('CUS-#####')),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '+2332'.fake()->unique()->numerify('########'),
            'gender' => fake()->randomElement(['male', 'female']),
            'date_of_birth' => fake()->dateTimeBetween('-65 years', '-18 years'),
            'id_type' => 'ghana_card',
            'id_number' => 'GHA-'.fake()->numerify('#########').'-'.fake()->randomDigit(),
            'next_of_kin_name' => fake()->name(),
            'next_of_kin_phone' => '+2332'.fake()->unique()->numerify('########'),
            'next_of_kin_relationship' => fake()->randomElement(['spouse', 'sibling', 'parent', 'child']),
            'address' => fake()->address(),
            'status' => 'active',
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes) => [
            'branch_id' => $branch->id,
            'company_id' => $branch->company_id,
        ]);
    }
}
