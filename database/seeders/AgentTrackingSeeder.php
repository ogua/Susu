<?php

namespace Database\Seeders;

use App\Actions\Agents\RecordLocationPingsAction;
use App\Actions\Agents\SetDutyStatusAction;
use App\Models\AgentDailySummary;
use App\Models\AgentLocationPing;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Seeds 10 field agents on the first company's first branch, each with a
 * live position and GPS trails for today and yesterday around Accra (with
 * stops along the way), so the Agent Tracking map and Track agent replay
 * have something to show. Mixes fresh, stale and off-duty agents.
 * Re-runnable: agents are matched by email and both days' trails are replaced.
 */
class AgentTrackingSeeder extends Seeder
{
    private const PINGS_TODAY = 40;

    private const PINGS_YESTERDAY = 72;

    /** @var list<array{name: string, portrait: string, area: string, lat: float, lng: float, state: string}> */
    private const AGENTS = [
        ['name' => 'Kwame Mensah', 'portrait' => 'men', 'area' => 'Makola Market', 'lat' => 5.5466, 'lng' => -0.2105, 'state' => 'fresh'],
        ['name' => 'Akosua Owusu', 'portrait' => 'women', 'area' => 'Kaneshie Market', 'lat' => 5.5667, 'lng' => -0.2367, 'state' => 'fresh'],
        ['name' => 'Kofi Boateng', 'portrait' => 'men', 'area' => 'Madina Zongo Junction', 'lat' => 5.6685, 'lng' => -0.1665, 'state' => 'fresh'],
        ['name' => 'Adwoa Asante', 'portrait' => 'women', 'area' => 'Osu Oxford Street', 'lat' => 5.5560, 'lng' => -0.1823, 'state' => 'fresh'],
        ['name' => 'Yaw Darko', 'portrait' => 'men', 'area' => 'Achimota Station', 'lat' => 5.6146, 'lng' => -0.2312, 'state' => 'fresh'],
        ['name' => 'Ama Frimpong', 'portrait' => 'women', 'area' => 'Dansoman Last Stop', 'lat' => 5.5389, 'lng' => -0.2683, 'state' => 'fresh'],
        ['name' => 'Kojo Tetteh', 'portrait' => 'men', 'area' => 'Teshie Nungua', 'lat' => 5.5853, 'lng' => -0.1022, 'state' => 'fresh'],
        ['name' => 'Efua Quaye', 'portrait' => 'women', 'area' => 'Lapaz', 'lat' => 5.6077, 'lng' => -0.2502, 'state' => 'stale'],
        ['name' => 'Selorm Agbeko', 'portrait' => 'men', 'area' => 'East Legon', 'lat' => 5.6350, 'lng' => -0.1543, 'state' => 'stale'],
        ['name' => 'Hawa Alhassan', 'portrait' => 'women', 'area' => 'Nima', 'lat' => 5.5822, 'lng' => -0.1985, 'state' => 'off_duty'],
    ];

    public function run(RecordLocationPingsAction $recordPings, SetDutyStatusAction $setDutyStatus): void
    {
        $company = Company::query()->oldest()->first();
        $branch = $company ? Branch::query()->where('company_id', $company->id)->oldest()->first() : null;

        if (! $branch) {
            $this->command?->warn('AgentTrackingSeeder skipped: no company/branch found. Run DemoSeeder first.');

            return;
        }

        foreach (self::AGENTS as $index => $profile) {
            $email = sprintf('tracking.agent%02d@demo.susuapp.test', $index + 1);

            $agent = User::query()->where('email', $email)->first()
                ?? User::factory()->fieldAgent($branch)->create([
                    'name' => $profile['name'],
                    'email' => $email,
                ]);

            $this->ensurePhoto($agent, $profile['portrait'], $index);

            AgentLocationPing::query()
                ->where('agent_id', $agent->id)
                ->where('recorded_at', '>=', now()->subDay()->startOfDay())
                ->delete();

            $setDutyStatus->execute($agent, true);

            // Yesterday first so today's last ping ends up as the live position.
            $recordPings->execute($agent, $this->trailFor(
                $profile['lat'] + fake()->randomFloat(4, 0.002, 0.012),
                $profile['lng'] + fake()->randomFloat(4, -0.01, 0.01),
                now()->subDay()->setTime(16, fake()->numberBetween(0, 59)),
                self::PINGS_YESTERDAY,
            ));

            $lastPingAt = $profile['state'] === 'fresh'
                ? now()->subMinutes(fake()->numberBetween(0, 4))
                : now()->subMinutes(fake()->numberBetween(30, 90));

            $recordPings->execute($agent, $this->trailFor($profile['lat'], $profile['lng'], $lastPingAt, self::PINGS_TODAY));

            AgentDailySummary::query()
                ->where('agent_id', $agent->id)
                ->where('summary_date', now()->toDateString())
                ->update([
                    'collections_count' => $count = fake()->numberBetween(3, 40),
                    'collections_total' => $count * fake()->numberBetween(5, 50) * 100,
                ]);

            if ($profile['state'] === 'off_duty') {
                $setDutyStatus->execute($agent, false);
            }
        }

        $this->command?->info(sprintf('Seeded %d tracked agents on "%s".', count(self::AGENTS), $branch->name));
    }

    /**
     * Downloads a stock portrait (randomuser.me) as the agent's photo so the
     * map shows faces; leaves the initials fallback in place when offline.
     */
    private function ensurePhoto(User $agent, string $portraitSet, int $index): void
    {
        if ($agent->photo_path && Storage::disk('public')->exists($agent->photo_path)) {
            return;
        }

        try {
            $response = Http::timeout(10)->get(sprintf('https://randomuser.me/api/portraits/%s/%d.jpg', $portraitSet, 20 + $index));
        } catch (\Throwable) {
            return;
        }

        if (! $response->successful()) {
            return;
        }

        $path = sprintf('staff/photos/tracking-agent%02d.jpg', $index + 1);
        Storage::disk('public')->put($path, $response->body());
        $agent->update(['photo_path' => $path]);
    }

    /**
     * A field round ending at the given point, one ping every 5 minutes:
     * walking legs with a gently drifting heading, broken up by 15–25 minute
     * stops (tiny GPS jitter in place) like visits to customers' stalls.
     *
     * @return list<array{latitude: float, longitude: float, accuracy: float, recorded_at: string}>
     */
    private function trailFor(float $endLat, float $endLng, \DateTimeInterface $endAt, int $count): array
    {
        $pings = [];
        $lat = $endLat;
        $lng = $endLng;
        $heading = fake()->randomFloat(2, 0, 2 * M_PI);
        $stopPingsLeft = 0;
        $walkPingsLeft = fake()->numberBetween(3, 7);

        for ($step = 0; $step < $count; $step++) {
            $pings[] = [
                'latitude' => round($lat, 7),
                'longitude' => round($lng, 7),
                'accuracy' => (float) fake()->numberBetween(5, 30),
                'recorded_at' => now()->setTimestamp($endAt->getTimestamp())->subMinutes($step * 5)->toISOString(),
            ];

            if ($stopPingsLeft > 0) {
                $stopPingsLeft--;
                $lat += fake()->randomFloat(5, -0.00008, 0.00008);
                $lng += fake()->randomFloat(5, -0.00008, 0.00008);

                continue;
            }

            if (--$walkPingsLeft <= 0) {
                $stopPingsLeft = fake()->numberBetween(3, 5);
                $walkPingsLeft = fake()->numberBetween(3, 7);
            }

            $heading += fake()->randomFloat(2, -0.6, 0.6);

            // Accra's coast is to the south: turn inland rather than walk into the sea.
            if ($lat < $endLat - 0.003) {
                $heading = M_PI / 2;
            }
            $legDegrees = fake()->randomFloat(5, 0.0008, 0.0018);
            $lat += sin($heading) * $legDegrees;
            $lng += cos($heading) * $legDegrees;
        }

        return array_reverse($pings);
    }
}
