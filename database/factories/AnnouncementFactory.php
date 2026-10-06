<?php

namespace Database\Factories;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementLevel;
use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(12),
            'level' => AnnouncementLevel::Info,
            'audience' => AnnouncementAudience::AllStaff,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ];
    }
}
