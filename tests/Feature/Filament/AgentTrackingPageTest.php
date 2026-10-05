<?php

use App\Actions\Agents\RecordLocationPingsAction;
use App\Actions\Agents\SetDutyStatusAction;
use App\Filament\Pages\AgentTracking;
use App\Models\AgentLivePosition;
use App\Models\Branch;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();
    $this->branch = Branch::factory()->create();
});

it('renders the tracking page for a branch manager and shows on-duty agents', function (): void {
    $manager = User::factory()->branchManager($this->branch)->create();
    $agent = User::factory()->fieldAgent($this->branch)->create();

    app(RecordLocationPingsAction::class)->execute($agent, [
        ['latitude' => 5.6037, 'longitude' => -0.1870, 'recorded_at' => now()->toISOString()],
    ]);

    $this->actingAs($manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(AgentTracking::class)
        ->assertOk()
        ->assertCanSeeTableRecords(AgentLivePosition::where('agent_id', $agent->id)->get());
});

it('denies field agents access to the tracking page', function (): void {
    $agent = User::factory()->fieldAgent($this->branch)->create();

    expect(AgentTracking::canAccess())->toBeFalse();

    $this->actingAs($agent);
    expect(AgentTracking::canAccess())->toBeFalse();
});

it('gives each map marker the agent photo, initials, status and today activity', function (): void {
    $manager = User::factory()->branchManager($this->branch)->create();
    $agent = User::factory()->fieldAgent($this->branch)->create(['name' => 'Kwame Mensah', 'photo_path' => 'staff/photos/kwame.jpg']);

    app(RecordLocationPingsAction::class)->execute($agent, [
        ['latitude' => 5.6000, 'longitude' => -0.1900, 'recorded_at' => now()->subMinutes(10)->toISOString()],
        ['latitude' => 5.6100, 'longitude' => -0.1900, 'recorded_at' => now()->toISOString()],
    ]);
    app(SetDutyStatusAction::class)->execute($agent, true);

    $this->actingAs($manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    $component = livewire(AgentTracking::class);
    $marker = collect($component->get('positions'))->firstWhere('id', $agent->id);

    expect($marker)
        ->initials->toBe('KM')
        ->first_name->toBe('Kwame')
        ->status->toBe('active')
        ->pings_today->toBe(2)
        ->photo_url->toEndWith('/storage/staff/photos/kwame.jpg');

    $component->call('selectAgent', $agent->id);

    expect($component->get('trail'))->toHaveCount(2)
        ->and($component->get('trailDistanceKm'))->toBeGreaterThan(1.0)->toBeLessThan(1.2);
});
