<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerFamilyMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerFamilyMember>
 */
class CustomerFamilyMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->name(),
            'relationship' => fake()->randomElement(['spouse', 'child', 'sibling', 'parent']),
            'contact_phone' => '+2332'.fake()->unique()->numerify('########'),
            'occupation' => fake()->jobTitle(),
        ];
    }
}
