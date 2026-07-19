<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerBeneficiary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerBeneficiary>
 */
class CustomerBeneficiaryFactory extends Factory
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
            'amount_of_legacy' => fake()->numberBetween(1, 50) * 100,
            'phone' => '+2332'.fake()->unique()->numerify('########'),
            'town' => fake()->city(),
        ];
    }
}
