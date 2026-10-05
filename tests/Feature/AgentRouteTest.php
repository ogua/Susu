<?php

use App\Actions\Agents\BuildAgentRouteAction;
use App\Actions\Agents\RecordLocationPingsAction;
use App\Enums\EntryStatus;
use App\Enums\TransactionType;
use App\Filament\Pages\AgentTracking;
use App\Filament\Pages\TrackAgent;
use App\Models\AgentLivePosition;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
});

/**
 * Records pings for the agent at the given minute offsets from 9:00 today.
 *
 * @param  array<int, array{0: int, 1: float, 2: float}>  $minuteLatLng
 */
function recordRoute(User $agent, array $minuteLatLng, ?CarbonImmutable $day = null): void
{
    $start = ($day ?? CarbonImmutable::today())->setTime(9, 0);

    app(RecordLocationPingsAction::class)->execute($agent, array_map(fn (array $ping): array => [
        'latitude' => $ping[1],
        'longitude' => $ping[2],
        'recorded_at' => $start->addMinutes($ping[0])->toIso8601String(),
    ], $minuteLatLng));
}

function bootTrackingPanel(Branch $branch): void
{
    Filament::setCurrentPanel('admin');
    Filament::setTenant($branch);
    Filament::bootCurrentPanel();
}

it('builds the route with distance, stops and geotagged collections', function (): void {
    recordRoute($this->agent, [
        [0, 5.6000, -0.1900],
        [5, 5.6001, -0.1900],   // ~11 m — same place
        [10, 5.6000, -0.1901],
        [15, 5.6001, -0.1901],  // 15 minutes within 75 m → one stop
        [20, 5.6100, -0.1900],  // walks ~1.1 km north
    ]);

    $entry = JournalEntry::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'type' => TransactionType::Collection,
        'status' => EntryStatus::Completed,
        'recorded_by' => $this->agent->id,
        'recorded_at' => CarbonImmutable::today()->setTime(9, 7),
        'latitude' => 5.6001,
        'longitude' => -0.1900,
        'description' => 'Daily susu — Ama Mensah',
    ]);
    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'ledger_account_id' => LedgerAccount::factory()->create(['company_id' => $this->branch->company_id])->id,
        'debit' => 2500,
    ]);

    $route = app(BuildAgentRouteAction::class)->execute($this->agent, CarbonImmutable::today());

    expect($route['points'])->toHaveCount(5)
        ->and($route['stops'])->toHaveCount(1)
        ->and($route['stops'][0]['minutes'])->toBe(15)
        ->and($route['collections'])->toHaveCount(1)
        ->and($route['collections'][0]['amount'])->toBe(2500)
        ->and($route['summary']['collections_total_formatted'])->toBe('GHS 25.00')
        ->and($route['summary']['duration_minutes'])->toBe(20)
        ->and($route['summary']['distance_km'])->toBeGreaterThan(1.0)->toBeLessThan(1.2);
});

it('only includes the requested day', function (): void {
    recordRoute($this->agent, [[0, 5.6, -0.19], [5, 5.61, -0.19]], CarbonImmutable::yesterday());
    recordRoute($this->agent, [[0, 5.7, -0.19]]);

    $yesterday = app(BuildAgentRouteAction::class)->execute($this->agent, CarbonImmutable::yesterday());

    expect($yesterday['points'])->toHaveCount(2)
        ->and($yesterday['date'])->toBe(CarbonImmutable::yesterday()->toDateString());
});

it('returns an agent route to their branch manager over the API', function (): void {
    recordRoute($this->agent, [[0, 5.6, -0.19], [5, 5.61, -0.19]]);
    Sanctum::actingAs($this->manager);

    $this->getJson("/api/v1/agents/{$this->agent->id}/route")
        ->assertOk()
        ->assertJsonPath('data.agent.id', $this->agent->id)
        ->assertJsonPath('data.date', CarbonImmutable::today()->toDateString())
        ->assertJsonPath('data.summary.points_count', 2)
        ->assertJsonStructure(['data' => ['agent' => ['name', 'photo_url', 'on_duty'], 'points', 'stops', 'collections', 'summary' => ['distance_km', 'stops_count']]]);
});

it('forbids managers of other branches and agents themselves from viewing a route', function (): void {
    $otherManager = User::factory()->branchManager(Branch::factory()->create(['company_id' => $this->branch->company_id]))->create();

    Sanctum::actingAs($otherManager);
    $this->getJson("/api/v1/agents/{$this->agent->id}/route")->assertForbidden();

    Sanctum::actingAs($this->agent);
    $this->getJson("/api/v1/agents/{$this->agent->id}/route")->assertForbidden();
});

it('rejects future dates', function (): void {
    Sanctum::actingAs($this->manager);

    $this->getJson("/api/v1/agents/{$this->agent->id}/route?date=".now()->addDay()->toDateString())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
});

it('renders the track agent page with the route for a past day', function (): void {
    recordRoute($this->agent, [[0, 5.6, -0.19], [5, 5.61, -0.19]], CarbonImmutable::yesterday());
    $this->actingAs($this->manager);
    bootTrackingPanel($this->branch);

    $component = livewire(TrackAgent::class, ['agent' => $this->agent->id, 'date' => CarbonImmutable::yesterday()->toDateString()])
        ->assertOk()
        ->assertSee($this->agent->name);

    expect($component->get('route')['summary']['points_count'])->toBe(2)
        ->and($component->get('route')['is_today'])->toBeFalse();
});

it('falls back to today for invalid or future dates on the track page', function (): void {
    $this->actingAs($this->manager);
    bootTrackingPanel($this->branch);

    livewire(TrackAgent::class, ['agent' => $this->agent->id, 'date' => now()->addWeek()->toDateString()])
        ->assertSet('date', now()->toDateString());
});

it('does not let a manager open the track page for an agent outside their branch', function (): void {
    $outsider = User::factory()->fieldAgent(Branch::factory()->create(['company_id' => $this->branch->company_id]))->create();
    $this->actingAs($this->manager);
    bootTrackingPanel($this->branch);

    livewire(TrackAgent::class, ['agent' => $outsider->id])->assertNotFound();
});

it('links each agent on the tracking page to their track page', function (): void {
    recordRoute($this->agent, [[0, 5.6, -0.19]]);
    $this->actingAs($this->manager);
    bootTrackingPanel($this->branch);

    $position = AgentLivePosition::where('agent_id', $this->agent->id)->firstOrFail();

    livewire(AgentTracking::class)
        ->assertActionVisible(TestAction::make('track')->table($position))
        ->assertActionHasUrl(TestAction::make('track')->table($position), TrackAgent::getUrl(['agent' => $this->agent->id]));
});

it('lists the branch agents live positions for a manager over the API', function (): void {
    recordRoute($this->agent, [[0, 5.6, -0.19], [5, 5.61, -0.19]]);
    Sanctum::actingAs($this->manager);

    $this->getJson('/api/v1/agents/positions')
        ->assertOk()
        ->assertJsonPath('branch.id', $this->branch->id)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->agent->id)
        ->assertJsonPath('data.0.pings_today', 2)
        ->assertJsonStructure(['data' => [['name', 'initials', 'color', 'photo_url', 'status', 'lat', 'lng', 'collections_total']]]);
});

it('does not list agent positions to field agents', function (): void {
    Sanctum::actingAs($this->agent);

    $this->getJson('/api/v1/agents/positions')->assertForbidden();
});
