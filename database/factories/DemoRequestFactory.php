<?php

namespace Database\Factories;

use App\Enums\DemoOrganisationType;
use App\Models\DemoRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoRequest>
 */
class DemoRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'organisation' => fake()->company().' Susu',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+23324'.fake()->numerify('#######'),
            'organisation_type' => fake()->randomElement(DemoOrganisationType::cases()),
            'branches_count' => fake()->numberBetween(1, 10),
            'message' => fake()->sentence(),
            'ip_address' => fake()->ipv4(),
        ];
    }

    public function handled(): static
    {
        return $this->state(fn (array $attributes) => ['handled_at' => now()]);
    }
}
