<?php

namespace Database\Factories;

use App\Enums\LicenseSaleStatus;
use App\Models\DesktopLicenseSale;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DesktopLicenseSale>
 */
class DesktopLicenseSaleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'install_id' => (string) Str::uuid(),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->unique()->safeEmail(),
            'customer_phone' => '+2332'.fake()->unique()->numerify('########'),
            'duration_days' => 365,
            'amount' => 500_00,
            'currency' => 'GHS',
            'status' => LicenseSaleStatus::Pending,
            'provider_reference' => (string) Str::uuid(),
            'source' => 'web_purchase',
        ];
    }
}
