<?php

namespace Database\Factories;

use App\Enums\LedgerAccountType;
use App\Models\Company;
use App\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerAccount>
 */
class LedgerAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => strtoupper(fake()->unique()->bothify('ACC-####')),
            'name' => fake()->words(2, true),
            'type' => LedgerAccountType::Asset,
            'balance' => 0,
            'currency' => 'GHS',
            'is_system' => false,
        ];
    }

    public function liability(): static
    {
        return $this->state(fn (array $attributes) => ['type' => LedgerAccountType::Liability]);
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => ['type' => LedgerAccountType::Income]);
    }
}
