<?php

namespace App\Actions\Agents;

use App\Models\AgentDailySummary;
use App\Models\AgentLivePosition;
use App\Models\AgentLocationPing;
use App\Models\Branch;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every agent with a known live position in a branch, with status and
 * today's activity — the data behind the Agent Tracking map on the web and
 * the agent list in the mobile app (AD-11). Amounts are minor units.
 */
class BuildAgentPositionsAction
{
    /** Agents pinging less recently than this show as "no recent signal". */
    public const STALE_MINUTES = 15;

    /** Distinct marker colours so agents are told apart at a glance. */
    private const AGENT_COLORS = ['#2563eb', '#db2777', '#ea580c', '#7c3aed', '#0d9488', '#ca8a04', '#dc2626', '#4f46e5', '#0891b2', '#65a30d'];

    /**
     * @return list<array{
     *     id: string, name: string, first_name: string, initials: string, color: string,
     *     phone: string|null, email: string|null, photo_url: string|null,
     *     status: 'active'|'stale'|'off_duty', on_duty: bool, stale: bool,
     *     lat: float, lng: float, located_at: string|null, located_at_human: string|null,
     *     pings_today: int, started_at: string|null,
     *     collections_count: int, collections_total: int, collections_total_formatted: string
     * }>
     */
    public function execute(Branch $branch): array
    {
        $today = now()->toDateString();

        $positions = AgentLivePosition::query()
            ->where('branch_id', $branch->id)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with('agent')
            ->get();

        $summaries = AgentDailySummary::query()
            ->where('branch_id', $branch->id)
            ->where('summary_date', $today)
            ->get()
            ->keyBy('agent_id');

        $activity = AgentLocationPing::query()
            ->where('branch_id', $branch->id)
            ->whereDate('recorded_at', $today)
            ->groupBy('agent_id')
            ->select('agent_id', DB::raw('COUNT(*) as pings_count'), DB::raw('MIN(recorded_at) as first_ping_at'))
            ->get()
            ->keyBy('agent_id');

        return $positions->map(function (AgentLivePosition $position) use ($summaries, $activity): array {
            $summary = $summaries->get($position->agent_id);
            $agentActivity = $activity->get($position->agent_id);
            $isStale = $position->located_at?->lt(now()->subMinutes(self::STALE_MINUTES)) ?? true;
            $name = $position->agent?->name ?? 'Unknown agent';
            $firstPingAt = $agentActivity?->first_ping_at ? Carbon::parse($agentActivity->first_ping_at) : null;
            $collectionsTotal = (int) ($summary?->collections_total ?? 0);

            return [
                'id' => $position->agent_id,
                'name' => $name,
                ...self::identity($position->agent_id, $name),
                'phone' => $position->agent?->phone,
                'email' => $position->agent?->email,
                'photo_url' => $position->agent?->photo_url,
                'status' => ! $position->on_duty ? 'off_duty' : ($isStale ? 'stale' : 'active'),
                'on_duty' => (bool) $position->on_duty,
                'stale' => $isStale,
                'lat' => (float) $position->latitude,
                'lng' => (float) $position->longitude,
                'located_at' => $position->located_at?->toIso8601String(),
                'located_at_human' => $position->located_at?->diffForHumans(),
                'pings_today' => (int) ($agentActivity?->pings_count ?? 0),
                'started_at' => $firstPingAt?->format('g:i A'),
                'collections_count' => (int) ($summary?->collections_count ?? 0),
                'collections_total' => $collectionsTotal,
                'collections_total_formatted' => Money::format($collectionsTotal),
            ];
        })->values()->all();
    }

    /**
     * Initials, short name and a stable per-agent colour, so an agent looks
     * the same on every map (web overview, Track agent page, mobile).
     *
     * @return array{first_name: string, initials: string, color: string}
     */
    public static function identity(string $agentId, string $name): array
    {
        return [
            'first_name' => Str::before($name, ' '),
            'initials' => Str::of($name)->explode(' ')->filter()->take(2)->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))->implode(''),
            'color' => self::AGENT_COLORS[crc32($agentId) % count(self::AGENT_COLORS)],
        ];
    }
}
