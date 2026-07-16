<?php

use App\Enums\LicenseSaleStatus;
use App\Filament\SuperAdmin\Widgets\PlatformOverview;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DesktopLicenseSale;
use App\Models\User;
use App\Support\Money;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();
    $superAdmin = User::factory()->superAdmin()->create();
    $this->actingAs($superAdmin);
    bootSuperAdminPanel();
});

it('counts companies, branches, and platform staff across the whole platform', function (): void {
    $activeCompany = Company::factory()->create(['is_active' => true]);
    Company::factory()->create(['is_active' => false]);

    // Pin both branches to the same (already-counted) company — Branch::factory()
    // otherwise spins up its own Company::factory() per branch, which would
    // silently inflate the "active companies" count this test asserts on.
    $branchA = Branch::factory()->create(['company_id' => $activeCompany->id]);
    $branchB = Branch::factory()->create(['company_id' => $activeCompany->id]);
    User::factory()->branchManager($branchA)->create();
    User::factory()->fieldAgent($branchB)->create();

    livewire(PlatformOverview::class)
        ->assertOk()
        ->assertSee('Companies')
        ->assertSee('1 active')
        ->assertSee('Branches')
        ->assertSee('Platform Staff');
});

it('excludes customer-role users from the platform staff count', function (): void {
    $company = Company::factory()->create();
    User::factory()->customerUser($company)->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    User::factory()->branchManager($branch)->create();

    $widget = new PlatformOverview;
    $method = new ReflectionMethod(PlatformOverview::class, 'platformStaff');
    $stat = $method->invoke($widget);

    // The acting super admin (beforeEach) + the branch manager = 2 staff;
    // the customer-role user must not be counted.
    expect($stat->getValue())->toBe('2');
});

it('sums this months paid and issued license revenue', function (): void {
    DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Issued,
        'amount' => 500_00,
        'created_at' => now(),
    ]);
    DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Failed,
        'amount' => 999_00,
        'created_at' => now(),
    ]);
    DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Issued,
        'amount' => 300_00,
        'created_at' => now()->subMonths(2),
    ]);

    livewire(PlatformOverview::class)
        ->assertOk()
        ->assertSee(Money::format(500_00));
});

it('denies non-super-admins access to the platform overview widget', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create();
    $this->actingAs($manager);

    expect($manager->canAccessPanel(Filament::getPanel('superadmin')))->toBeFalse();
});
