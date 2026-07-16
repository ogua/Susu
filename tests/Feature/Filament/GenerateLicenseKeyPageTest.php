<?php

use App\Enums\LicenseSaleStatus;
use App\Filament\SuperAdmin\Pages\GenerateLicenseKey;
use App\Models\Branch;
use App\Models\DesktopLicenseSale;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    seedRoles();
    Config::set('license.private_key_path', __DIR__.'/../../Fixtures/license/test_private_key.pem');
});

function bootSuperAdminPanel(): void
{
    Filament::setCurrentPanel('superadmin');
    Filament::bootCurrentPanel();
}

it('lets a super admin generate a key from the panel', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $this->actingAs($superAdmin);
    bootSuperAdminPanel();

    livewire(GenerateLicenseKey::class)
        ->assertOk()
        ->callAction('generate', [
            'install_id' => 'install-super-1',
            'duration_days' => 30,
            'customer_name' => 'Support Case',
            'customer_email' => 'support@example.com',
            'customer_phone' => null,
        ])
        ->assertNotified();

    $sale = DesktopLicenseSale::where('install_id', 'install-super-1')->first();
    expect($sale)->not->toBeNull()
        ->and($sale->status)->toBe(LicenseSaleStatus::Issued)
        ->and($sale->license_key)->not->toBeNull()
        ->and($sale->source)->toBe('admin_manual')
        ->and($sale->issued_by)->toBe($superAdmin->id);
});

it('denies non-super-admins access to the page', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create();
    $this->actingAs($manager);

    expect(GenerateLicenseKey::canAccess())->toBeFalse();
});
