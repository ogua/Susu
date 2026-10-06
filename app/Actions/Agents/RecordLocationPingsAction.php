<?php

namespace App\Actions\Agents;

use App\Models\AgentLivePosition;
use App\Models\AgentLocationPing;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Batch-stores duty-scoped GPS pings and refreshes the agent's live position
 * for the tracking map (AD-11). Only field agents are tracked.
 *
 * @phpstan-type Ping array{latitude: float, longitude: float, accuracy?: float|null, recorded_at: string}
 */
class RecordLocationPingsAction
{
    /**
     * @param  array<int, Ping>  $pings
     */
    public function execute(User $agent, array $pings): int
    {
        if ($pings === [] || $agent->branch_id === null || ! $agent->hasRole('field_agent')) {
            return 0;
        }

        $now = now();
        $rows = [];
        $latest = null;

        foreach ($pings as $ping) {
            $recordedAt = Carbon::parse($ping['recorded_at']);
            $rows[] = [
                'id' => (string) Str::uuid(),
                'company_id' => $agent->company_id,
                'branch_id' => $agent->branch_id,
                'agent_id' => $agent->id,
                'latitude' => $ping['latitude'],
                'longitude' => $ping['longitude'],
                'accuracy' => $ping['accuracy'] ?? null,
                'recorded_at' => $recordedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($latest === null || $recordedAt->greaterThan($latest['recorded_at'])) {
                $latest = ['recorded_at' => $recordedAt, 'latitude' => $ping['latitude'], 'longitude' => $ping['longitude']];
            }
        }

        AgentLocationPing::insert($rows);

        AgentLivePosition::updateOrCreate(
            ['agent_id' => $agent->id],
            [
                'company_id' => $agent->company_id,
                'branch_id' => $agent->branch_id,
                'latitude' => $latest['latitude'],
                'longitude' => $latest['longitude'],
                'located_at' => $latest['recorded_at'],
            ],
        );

        return count($rows);
    }
}
