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
