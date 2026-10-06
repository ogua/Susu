<?php

use App\Models\Branch;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();
});

it('denies non-super-admins access to the super-admin panel', function (): void {
    $branch = Branch::factory()->create();
    $agent = User::factory()->fieldAgent($branch)->create();

    expect($agent->canAccessPanel(Filament::getPanel('superadmin')))->toBeFalse();
});

it('allows super admins into the super-admin panel', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();

    expect($superAdmin->canAccessPanel(Filament::getPanel('superadmin')))->toBeTrue();
});

it('denies customers access to the admin panel', function (): void {
    $customer = User::factory()->customerUser()->create();

    expect($customer->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('denies deactivated staff access to the admin panel', function (): void {
    $branch = Branch::factory()->create();
    $agent = User::factory()->inactive()->fieldAgent($branch)->create();

    expect($agent->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('refuses panel requests from users the panel does not allow (enforced over HTTP, not just canAccessPanel)', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create();
    $customer = User::factory()->customerUser($branch->company)->create();

    $this->actingAs($manager)->get('/super-admin')->assertForbidden();
    $this->actingAs($customer)->get('/admin')->assertForbidden();
});

it('refuses the admin panel to staff of a suspended company', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create();
    $branch->company->update(['is_active' => false]);

    $this->actingAs($manager)->get('/admin/'.$branch->slug)->assertForbidden();
});

it('lets a super admin into the super-admin panel over HTTP', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)->get('/super-admin')->assertOk();
});
