<?php

namespace App\Filament\Pages;

use App\Actions\Agents\BuildAgentRouteAction;
use App\Models\AgentDailySummary;
use App\Models\AgentLivePosition;
use App\Models\AgentLocationPing;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Live view of every agent currently on duty for the tenant branch: an
 * interactive Leaflet/OSM map (click a marker to zoom + see details) backed
 * by a polling table underneath for quick scanning/search — the Uber-style
 * tracking dashboard (AD-11).
 */
class AgentTracking extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.agent-tracking';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'Agent Tracking';

    /** Agents pinging less recently than this are shown greyed on the map. */
    private const STALE_MINUTES = 15;

    public ?string $selectedAgentId = null;

    /** @var array<int, array{lat: float, lng: float, at: string}> */
    public array $trail = [];

    /** Distance covered along the selected agent's trail today, in km. */
    public float $trailDistanceKm = 0.0;

    /** Distinct marker colours so agents are told apart at a glance. */
    private const AGENT_COLORS = ['#2563eb', '#db2777', '#ea580c', '#7c3aed', '#0d9488', '#ca8a04', '#dc2626', '#4f46e5', '#0891b2', '#65a30d'];

    /**
     * Entangled with Alpine's `positions` state so the map re-renders on
     * every poll without Alpine losing its component state (`@entangle`
     * pushes updates into already-initialized Alpine data; a plain `@js()`
     * snapshot would only ever render once, at first paint).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $positions = [];

    public function mount(): void
    {
        $this->refreshPositions();
    }

    public function refreshPositions(): void
    {
        $this->positions = $this->buildPositions();
    }

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole(['company_admin', 'branch_manager']) ?? false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPositions(): array
    {
        $positions = AgentLivePosition::query()
            ->where('branch_id', Filament::getTenant()?->id)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with('agent')
            ->get();

        $today = now()->toDateString();
        $summaries = AgentDailySummary::query()
            ->where('branch_id', Filament::getTenant()?->id)
            ->where('summary_date', $today)
            ->get()
            ->keyBy('agent_id');

        $activity = AgentLocationPing::query()
            ->where('branch_id', Filament::getTenant()?->id)
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

            return [
                'id' => $position->agent_id,
                'name' => $name,
                ...self::agentIdentity($position->agent_id, $name),
                'track_url' => TrackAgent::getUrl(['agent' => $position->agent_id]),
                'phone' => $position->agent?->phone,
                'email' => $position->agent?->email,
                'photo_url' => $position->agent?->photo_url,
                'status' => ! $position->on_duty ? 'off_duty' : ($isStale ? 'stale' : 'active'),
                'pings_today' => (int) ($agentActivity?->pings_count ?? 0),
                'started_at' => $firstPingAt?->format('g:i A'),
                'lat' => $position->latitude,
                'lng' => $position->longitude,
                'on_duty' => $position->on_duty,
                'stale' => $isStale,
                'located_at' => $position->located_at?->toISOString(),
                'located_at_human' => $position->located_at?->diffForHumans(),
                'collections_count' => $summary?->collections_count ?? 0,
                'collections_total_formatted' => $summary?->collections_total_formatted ?? 'GHS 0.00',
            ];
        })->values()->all();
    }

    /** Loads today's ping trail for one agent, called from the map's detail panel. */
    public function selectAgent(string $agentId): void
    {
        $agent = AgentLivePosition::query()
            ->where('branch_id', Filament::getTenant()?->id)
            ->where('agent_id', $agentId)
            ->with('agent')
            ->first()
            ?->agent;

        if (! $agent) {
            return;
        }

        $this->selectedAgentId = $agentId;
        $route = app(BuildAgentRouteAction::class)->execute($agent, now());

        $this->trail = array_map(fn (array $point): array => [
            'lat' => $point['lat'],
            'lng' => $point['lng'],
            'at' => $point['time'],
        ], $route['points']);
        $this->trailDistanceKm = $route['summary']['distance_km'];
    }

    /**
     * Initials, short name and a stable per-agent colour, shared by the
     * tracking map and the single-agent Track page.
     *
     * @return array{first_name: string, initials: string, color: string}
     */
    public static function agentIdentity(string $agentId, string $name): array
    {
        return [
            'first_name' => Str::before($name, ' '),
            'initials' => Str::of($name)->explode(' ')->filter()->take(2)->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))->implode(''),
            'color' => self::AGENT_COLORS[crc32($agentId) % count(self::AGENT_COLORS)],
        ];
    }

    public function clearSelection(): void
    {
        $this->selectedAgentId = null;
        $this->trail = [];
        $this->trailDistanceKm = 0.0;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AgentLivePosition::query()
                    ->where('branch_id', Filament::getTenant()?->id)
                    ->with('agent')
            )
            ->columns([
                TextColumn::make('agent.name')->label('Agent')->searchable(),
                TextColumn::make('agent.phone')->label('Phone'),
                IconColumn::make('on_duty')->boolean(),
                TextColumn::make('latitude')->label('Lat'),
                TextColumn::make('longitude')->label('Lng'),
                TextColumn::make('located_at')->label('Last seen')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('track')
                    ->label('Track agent')
                    ->icon(Heroicon::OutlinedMap)
                    ->url(fn (AgentLivePosition $record): string => TrackAgent::getUrl(['agent' => $record->agent_id]))
                    ->visible(fn (AgentLivePosition $record): bool => $record->agent !== null
                        && (Filament::auth()->user()?->can('trackRoute', $record->agent) ?? false)),
            ])
            ->poll('10s')
            ->defaultSort('on_duty', 'desc');
    }
}
