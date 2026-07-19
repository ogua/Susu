<?php

namespace Database\Factories;

use App\Enums\IdentificationType;
use App\Models\Customer;
use App\Models\CustomerIdentification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerIdentification>
 */
class CustomerIdentificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'id_type' => IdentificationType::GhanaCard,
            'id_number' => 'GHA-'.fake()->numerify('#########').'-'.fake()->randomDigit(),
            'issue_date' => fake()->dateTimeBetween('-5 years', '-1 year'),
            'expiry_date' => fake()->dateTimeBetween('+1 year', '+9 years'),
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }
}
