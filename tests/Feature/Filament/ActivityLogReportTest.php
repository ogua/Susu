<?php

use App\Filament\Pages\ActivityLogReport;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsProduct;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    seedRoles();

    $this->branchA = Branch::factory()->create();
    $this->branchB = Branch::factory()->create(['company_id' => $this->branchA->company_id]);
    $this->companyAdmin = User::factory()->companyAdmin($this->branchA->company)->create();
    $this->managerA = User::factory()->branchManager($this->branchA)->create();
    $this->managerB = User::factory()->branchManager($this->branchB)->create();
});

it('logs customer field changes but never the KYC id number or photo paths', function (): void {
    $this->actingAs($this->companyAdmin);

    $customer = Customer::factory()->forBranch($this->branchA)->create([
        'id_number' => 'GHA-999999999-0',
        'first_name' => 'Ama',
    ]);
    $customer->update(['first_name' => 'Akosua', 'id_number' => 'GHA-111111111-0']);

    $activity = Activity::forSubject($customer)->latest()->first();

    bootAdminPanelWithTenant($this->branchA);

    livewire(ActivityLogReport::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$activity])
        ->assertSee('first_name');

    expect($activity->properties->get('attributes'))
        ->toHaveKey('first_name')
        ->not->toHaveKey('id_number')
        ->not->toHaveKey('id_photo_path');
});

it('lets a branch manager see product changes company-wide but not another branch\'s customer changes', function (): void {
    $this->actingAs($this->companyAdmin);

    $customerA = Customer::factory()->forBranch($this->branchA)->create(['first_name' => 'Kofi']);
    $customerA->update(['first_name' => 'Yaw']);
    $customerActivity = Activity::forSubject($customerA)->latest()->first();

    $product = SavingsProduct::factory()->create(['company_id' => $this->branchA->company_id, 'name' => 'Daily Susu']);
    $product->update(['name' => 'Daily Susu Plus']);
    $productActivity = Activity::forSubject($product)->latest()->first();

    $this->actingAs($this->managerB);
    bootAdminPanelWithTenant($this->branchB);

    livewire(ActivityLogReport::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$productActivity])
        ->assertCanNotSeeTableRecords([$customerActivity]);
});

it('denies field agents access to the activity log', function (): void {
    $agent = User::factory()->fieldAgent($this->branchA)->create();
    $this->actingAs($agent);

    expect(ActivityLogReport::canAccess())->toBeFalse();
});
