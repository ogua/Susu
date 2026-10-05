<?php

namespace App\Filament\Pages;

use App\Actions\Agents\BuildAgentPositionsAction;
use App\Actions\Agents\BuildAgentRouteAction;
use App\Models\AgentLivePosition;
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

    public ?string $selectedAgentId = null;

    /** @var array<int, array{lat: float, lng: float, at: string}> */
    public array $trail = [];

    /** Distance covered along the selected agent's trail today, in km. */
    public float $trailDistanceKm = 0.0;

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
        $branch = Filament::getTenant();

        if (! $branch) {
            return [];
        }

        return array_map(fn (array $position): array => [
            ...$position,
            'track_url' => TrackAgent::getUrl(['agent' => $position['id']]),
        ], app(BuildAgentPositionsAction::class)->execute($branch));
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
