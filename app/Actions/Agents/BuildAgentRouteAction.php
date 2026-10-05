<?php

namespace App\Actions\Agents;

use App\Enums\EntryStatus;
use App\Enums\TransactionType;
use App\Models\AgentLocationPing;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Reconstructs one agent's movement for a day from their duty-scoped GPS
 * pings (AD-11): the route itself, distance covered, places they stopped,
 * and where each geotagged collection was recorded. Shared by the web
 * "Track agent" page and the API so both show the same numbers.
 *
 * @phpstan-type RoutePoint array{lat: float, lng: float, accuracy: float|null, at: string, time: string}
 * @phpstan-type RouteStop array{lat: float, lng: float, arrived_at: string, left_at: string, arrived_time: string, left_time: string, minutes: int}
 * @phpstan-type RouteCollection array{reference: string, description: string|null, amount: int, amount_formatted: string, lat: float, lng: float, at: string, time: string}
 */
class BuildAgentRouteAction
{
    /** Pings within this many metres of where a stop began count as the same place. */
    public const STOP_RADIUS_METRES = 75;

    /** Staying put for at least this long counts as a stop. */
    public const STOP_MIN_MINUTES = 10;

    private const EARTH_RADIUS_KM = 6371;

    /**
     * @return array{
     *     date: string,
     *     points: list<RoutePoint>,
     *     stops: list<RouteStop>,
     *     collections: list<RouteCollection>,
     *     summary: array{distance_km: float, started_at: string|null, ended_at: string|null, started_time: string|null, ended_time: string|null, duration_minutes: int, points_count: int, stops_count: int, collections_count: int, collections_total: int, collections_total_formatted: string}
     * }
     */
    public function execute(User $agent, CarbonInterface $date): array
    {
        $pings = AgentLocationPing::query()
            ->where('agent_id', $agent->id)
            ->whereBetween('recorded_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'accuracy', 'recorded_at']);

        $points = $pings->map(fn (AgentLocationPing $ping): array => [
            'lat' => (float) $ping->latitude,
            'lng' => (float) $ping->longitude,
            'accuracy' => $ping->accuracy !== null ? (float) $ping->accuracy : null,
            'at' => $ping->recorded_at->toIso8601String(),
            'time' => $ping->recorded_at->format('g:i A'),
        ])->values()->all();

        $collections = $this->collections($agent, $date);
        $stops = $this->stops($pings);
        $first = $pings->first()?->recorded_at;
        $last = $pings->last()?->recorded_at;
        $collectionsTotal = array_sum(array_column($collections, 'amount'));

        return [
            'date' => $date->toDateString(),
            'points' => $points,
            'stops' => $stops,
            'collections' => $collections,
            'summary' => [
                'distance_km' => round($this->distanceKm($points), 2),
                'started_at' => $first?->toIso8601String(),
                'ended_at' => $last?->toIso8601String(),
                'started_time' => $first?->format('g:i A'),
                'ended_time' => $last?->format('g:i A'),
                'duration_minutes' => $first && $last ? (int) $first->diffInMinutes($last) : 0,
                'points_count' => count($points),
                'stops_count' => count($stops),
                'collections_count' => count($collections),
                'collections_total' => $collectionsTotal,
                'collections_total_formatted' => Money::format($collectionsTotal),
            ],
        ];
    }

    /**
     * Completed collections the agent recorded that day which carry GPS.
     *
     * @return list<RouteCollection>
     */
    private function collections(User $agent, CarbonInterface $date): array
    {
        return JournalEntry::query()
            ->where('recorded_by', $agent->id)
            ->where('type', TransactionType::Collection)
            ->where('status', EntryStatus::Completed)
            ->whereBetween('recorded_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->withSum('lines as amount_sum', 'debit')
            ->orderBy('recorded_at')
            ->get()
            ->map(fn (JournalEntry $entry): array => [
                'reference' => $entry->reference,
                'description' => $entry->description,
                'amount' => (int) $entry->amount_sum,
                'amount_formatted' => Money::format((int) $entry->amount_sum),
                'lat' => (float) $entry->latitude,
                'lng' => (float) $entry->longitude,
                'at' => $entry->recorded_at->toIso8601String(),
                'time' => $entry->recorded_at->format('g:i A'),
            ])
            ->values()
            ->all();
    }

    /**
     * Groups consecutive pings that stay within STOP_RADIUS_METRES of where
     * the group began; groups lasting STOP_MIN_MINUTES or more are stops.
     *
     * @param  Collection<int, AgentLocationPing>  $pings
     * @return list<RouteStop>
     */
    private function stops(Collection $pings): array
    {
        $stops = [];
        $count = $pings->count();
        $start = 0;

        while ($start < $count) {
            $anchor = $pings[$start];
            $end = $start;

            while ($end + 1 < $count && $this->metresBetween($anchor, $pings[$end + 1]) <= self::STOP_RADIUS_METRES) {
                $end++;
            }

            $minutes = (int) $anchor->recorded_at->diffInMinutes($pings[$end]->recorded_at);

            if ($end > $start && $minutes >= self::STOP_MIN_MINUTES) {
                $group = $pings->slice($start, $end - $start + 1);
                $stops[] = [
                    'lat' => round($group->avg(fn (AgentLocationPing $ping): float => (float) $ping->latitude), 7),
                    'lng' => round($group->avg(fn (AgentLocationPing $ping): float => (float) $ping->longitude), 7),
                    'arrived_at' => $anchor->recorded_at->toIso8601String(),
                    'left_at' => $pings[$end]->recorded_at->toIso8601String(),
                    'arrived_time' => $anchor->recorded_at->format('g:i A'),
                    'left_time' => $pings[$end]->recorded_at->format('g:i A'),
                    'minutes' => $minutes,
                ];
                $start = $end + 1;

                continue;
            }

            $start++;
        }

        return $stops;
    }

    private function metresBetween(AgentLocationPing $from, AgentLocationPing $to): float
    {
        return $this->haversineKm((float) $from->latitude, (float) $from->longitude, (float) $to->latitude, (float) $to->longitude) * 1000;
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $points
     */
    private function distanceKm(array $points): float
    {
        $totalKm = 0.0;

        for ($i = 1; $i < count($points); $i++) {
            $totalKm += $this->haversineKm($points[$i - 1]['lat'], $points[$i - 1]['lng'], $points[$i]['lat'], $points[$i]['lng']);
        }

        return $totalKm;
    }

    private function haversineKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $latDelta = deg2rad($toLat - $fromLat);
        $lngDelta = deg2rad($toLng - $fromLng);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lngDelta / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
