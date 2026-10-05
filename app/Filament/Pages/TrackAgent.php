<?php

namespace App\Filament\Pages;

use App\Actions\Agents\BuildAgentRouteAction;
use App\Models\AgentLivePosition;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Follows a single field agent: live position (today) or a replay of any
 * past day — route, stops, and where collections were recorded — reached
 * from the "Track agent" action on the Agent Tracking page (AD-11).
 */
class TrackAgent extends Page
{
    protected string $view = 'filament.pages.track-agent';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $slug = 'agent-tracking/track';

    protected static bool $shouldRegisterNavigation = false;

    /** Live signals older than this read as "no recent signal". */
    private const STALE_MINUTES = 15;

    #[Url]
    public ?string $agent = null;

    #[Url]
    public ?string $date = null;

    /** @var array<string, mixed> */
    public array $agentInfo = [];

    /** @var array<string, mixed> */
    public array $route = [];

    public static function canAccess(): bool
    {
        return AgentTracking::canAccess();
    }

    public function mount(): void
    {
        $this->date = $this->normalisedDate($this->date);
        $this->loadRoute();
    }

    public function getTitle(): string
    {
        return 'Track '.($this->agentInfo['name'] ?? 'agent');
    }

    public function updatedAgent(): void
    {
        $this->loadRoute();
    }

    public function updatedDate(): void
    {
        $this->date = $this->normalisedDate($this->date);
        $this->loadRoute();
    }

    /** Polled while viewing today so the live position and route keep up. */
    public function refreshLive(): void
    {
        if ($this->isToday()) {
            $this->loadRoute();
        }
    }

    public function isToday(): bool
    {
        return $this->date === now()->toDateString();
    }

    /**
     * Field agents the viewer may track, for the agent switcher.
     *
     * @return array<string, string>
     */
    public function agentOptions(): array
    {
        $viewer = Filament::auth()->user();

        return User::query()
            ->role('field_agent')
            ->whereHas('branches', fn ($query) => $query->where('branches.id', Filament::getTenant()?->id))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $agent): bool => $viewer->can('trackRoute', $agent))
            ->mapWithKeys(fn (User $agent): array => [$agent->id => $agent->name])
            ->all();
    }

    private function loadRoute(): void
    {
        $agent = User::query()->find($this->agent);

        abort_unless($agent && Filament::auth()->user()->can('trackRoute', $agent), 404);

        $position = AgentLivePosition::query()->where('agent_id', $agent->id)->first();
        $isStale = $position?->located_at?->lt(now()->subMinutes(self::STALE_MINUTES)) ?? true;

        $this->agentInfo = [
            'id' => $agent->id,
            'name' => $agent->name,
            ...AgentTracking::agentIdentity($agent->id, $agent->name),
            'phone' => $agent->phone,
            'photo_url' => $agent->photo_url,
            'status' => ! $position?->on_duty ? 'off_duty' : ($isStale ? 'stale' : 'active'),
            'live_lat' => $position?->latitude,
            'live_lng' => $position?->longitude,
            'located_at_human' => $position?->located_at?->diffForHumans(),
        ];

        $this->route = [
            ...app(BuildAgentRouteAction::class)->execute($agent, CarbonImmutable::parse($this->date)),
            'is_today' => $this->isToday(),
        ];
    }

    private function normalisedDate(?string $date): string
    {
        try {
            $parsed = $date ? (CarbonImmutable::createFromFormat('Y-m-d', $date) ?: null) : null;
        } catch (\Throwable) {
            $parsed = null;
        }

        return $parsed && $parsed->lte(today()) ? $parsed->toDateString() : now()->toDateString();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('All agents')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(AgentTracking::getUrl()),
        ];
    }
}
