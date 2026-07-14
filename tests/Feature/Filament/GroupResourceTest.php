<?php

use App\Actions\Groups\AddGroupMemberAction;
use App\Enums\GroupStatus;
use App\Filament\Resources\Groups\Pages\CreateGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Group;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
});

it('lets a branch manager create a group via the Filament create page', function (): void {
    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(CreateGroup::class)
        ->fillForm([
            'name' => 'Market Women Susu',
            'code' => 'MWS-001',
            'contribution_amount' => '10.00',
            'frequency' => 'monthly',
        ])
        ->call('create')
        ->assertNotified();

    $group = Group::where('code', 'MWS-001')->firstOrFail();
    expect($group->contribution_amount)->toBe(1000)
        ->and($group->status)->toBe(GroupStatus::Draft)
        ->and($group->branch_id)->toBe($this->branch->id);
});

it('renders the groups list scoped to the tenant branch', function (): void {
    $ownGroup = Group::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);

    $otherBranch = Branch::factory()->create(['company_id' => $this->branch->company_id]);
    $otherGroup = Group::factory()->create(['company_id' => $otherBranch->company_id, 'branch_id' => $otherBranch->id]);

    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListGroups::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$ownGroup])
        ->assertCanNotSeeTableRecords([$otherGroup]);
});

it('lets a branch manager activate a group from the table action', function (): void {
    $group = Group::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);
    $customerOne = Customer::factory()->forBranch($this->branch)->create();
    $customerTwo = Customer::factory()->forBranch($this->branch)->create();
    app(AddGroupMemberAction::class)->execute($group, $customerOne, 1);
    app(AddGroupMemberAction::class)->execute($group, $customerTwo, 2);

    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListGroups::class)
        ->assertOk()
        ->callAction(TestAction::make('activate')->table($group))
        ->assertNotified();

    expect($group->refresh()->status)->toBe(GroupStatus::Active)
        ->and($group->rounds)->toHaveCount(2);
});

it('denies field agents from activating a group', function (): void {
    $group = Group::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);

    $this->actingAs($this->agent);

    expect($this->agent->can('activate', $group))->toBeFalse()
        ->and($this->agent->can('recordContribution', $group))->toBeTrue();
});
