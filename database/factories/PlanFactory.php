<?php

namespace Database\Factories;

use App\Enums\BillingPeriod;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Starter', 'Growth', 'Business', 'Enterprise', 'Community', 'Pro']).' '.fake()->unique()->numberBetween(1, 9999);

        return [
            'name' => $name,
            'code' => Str::upper(Str::slug($name, '_')),
            'price_amount' => 200_00,
            'currency' => 'GHS',
            'billing_period' => BillingPeriod::Monthly,
            'trial_days' => 0,
            'max_branches' => null,
            'max_staff' => null,
            'max_customers' => null,
            'is_active' => true,
        ];
    }

    public function withTrial(int $days = 14): static
    {
        return $this->state(fn (array $attributes) => ['trial_days' => $days]);
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes) => ['price_amount' => 0]);
    }

    /**
     * @param  array{branches?: int, staff?: int, customers?: int}  $limits
     */
    public function limited(array $limits): static
    {
        return $this->state(fn (array $attributes) => [
            'max_branches' => $limits['branches'] ?? null,
            'max_staff' => $limits['staff'] ?? null,
            'max_customers' => $limits['customers'] ?? null,
        ]);
    }
}
