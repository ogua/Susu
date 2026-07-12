<?php

namespace App\Filament\Pages;

use App\Models\AgentDailySummary;
use App\Models\AgentLivePosition;
use App\Models\AgentLocationPing;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

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

    /** @var array<int, array{lat: float, lng: float}> */
    public array $trail = [];

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

        return $positions->map(function (AgentLivePosition $position) use ($summaries): array {
            $summary = $summaries->get($position->agent_id);
            $isStale = $position->located_at?->lt(now()->subMinutes(self::STALE_MINUTES)) ?? true;

            return [
                'id' => $position->agent_id,
                'name' => $position->agent?->name,
                'phone' => $position->agent?->phone,
                'photo_url' => $position->agent?->photo_path ? Storage::url($position->agent->photo_path) : null,
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
        $this->selectedAgentId = $agentId;

        $this->trail = AgentLocationPing::query()
            ->where('agent_id', $agentId)
            ->whereDate('recorded_at', now()->toDateString())
            ->orderBy('recorded_at')
            ->get(['latitude', 'longitude'])
            ->map(fn (AgentLocationPing $ping): array => ['lat' => $ping->latitude, 'lng' => $ping->longitude])
            ->all();
    }

    public function clearSelection(): void
    {
        $this->selectedAgentId = null;
        $this->trail = [];
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
            ->poll('10s')
            ->defaultSort('on_duty', 'desc');
    }
}
