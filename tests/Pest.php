<?php

use App\Models\Branch;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every line of code in your test files.
|
*/

/**
 * Test a Livewire component. Stand-in for pestphp/pest-plugin-livewire's
 * helper (that plugin needs PHP >= 8.3 with Livewire 4).
 *
 * @param  class-string  $component
 * @param  array<string, mixed>  $params
 */
function livewire(string $component, array $params = []): Testable
{
    return Livewire::test($component, $params);
}

/**
 * Seed the application roles (most feature tests need them).
 */
function seedRoles(): void
{
    (new RoleSeeder)->run();
}

/**
 * Boots the SuperAdmin panel context for a Livewire test — that panel has no
 * tenancy, so (unlike bootAdminPanelWithTenant()) there's no tenant to set.
 */
function bootSuperAdminPanel(): void
{
    Filament::setCurrentPanel('superadmin');
    Filament::bootCurrentPanel();
}

/**
 * Booting the panel registers Filament's automatic tenant-association
 * listener, which force-sets branch_id on every NEW Customer to the current
 * tenant — so fixtures for other branches must be created before this runs.
 */
function bootAdminPanelWithTenant(Branch $branch): void
{
    Filament::setCurrentPanel('admin');
    Filament::setTenant($branch);
    Filament::bootCurrentPanel();
}
