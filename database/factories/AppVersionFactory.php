<?php

namespace Database\Factories;

use App\Enums\AppPlatform;
use App\Models\AppVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppVersion>
 */
class AppVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $minor = fake()->unique()->numberBetween(1, 900);

        return [
            'platform' => AppPlatform::Android,
            'version' => "1.{$minor}.0",
            'version_code' => $minor,
            'minimum_supported_version' => null,
            'is_forced_update' => false,
            'is_active' => true,
            'release_notes' => fake()->sentence(),
            'store_url' => 'https://play.google.com/store/apps/details?id=com.oguaschoolz.susuapp',
            'released_at' => now(),
        ];
    }

    public function forced(): static
    {
        return $this->state(['is_forced_update' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
