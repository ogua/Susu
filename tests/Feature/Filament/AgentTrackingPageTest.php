<?php

use App\Actions\Agents\RecordLocationPingsAction;
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
