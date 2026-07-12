<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();
});

it('lists only the branches a user belongs to as tenants', function (): void {
    $company = Company::factory()->create();
    $own = Branch::factory()->for($company)->create();
    $other = Branch::factory()->for($company)->create();

    $agent = User::factory()->fieldAgent($own)->create();

    $tenants = $agent->getTenants(Filament::getPanel('admin'));

    expect($tenants->pluck('id')->all())->toBe([$own->id])
        ->and($agent->canAccessTenant($own))->toBeTrue()
        ->and($agent->canAccessTenant($other))->toBeFalse();
});

it('gives a company admin access to every branch of their company', function (): void {
    $company = Company::factory()->create();
    $branches = Branch::factory()->count(2)->for($company)->create();

    $admin = User::factory()->companyAdmin($company)->create();

    expect($admin->getTenants(Filament::getPanel('admin'))->pluck('id')->sort()->values()->all())
        ->toBe($branches->pluck('id')->sort()->values()->all());
});
