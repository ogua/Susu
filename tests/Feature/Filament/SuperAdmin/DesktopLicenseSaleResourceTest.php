<?php

use App\Enums\LicenseSaleStatus;
use App\Filament\SuperAdmin\Resources\DesktopLicenseSales\Pages\ListDesktopLicenseSales;
use App\Filament\SuperAdmin\Resources\DesktopLicenseSales\Pages\ViewDesktopLicenseSale;
use App\Models\Branch;
use App\Models\DesktopLicenseSale;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    seedRoles();
    Config::set('license.private_key_path', __DIR__.'/../../../Fixtures/license/test_private_key.pem');
    $this->superAdmin = User::factory()->superAdmin()->create();
    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();
});

it('lets a super admin generate a key from the panel', function (): void {
    livewire(ListDesktopLicenseSales::class)
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
        ->and($sale->issued_by)->toBe($this->superAdmin->id);
});

it('lists both web-purchase and admin-manual sales, unlike the old admin-only page', function (): void {
    $web = DesktopLicenseSale::factory()->create(['source' => 'web_purchase']);
    $manual = DesktopLicenseSale::factory()->create(['source' => 'admin_manual']);

    livewire(ListDesktopLicenseSales::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$web, $manual]);
});

it('filters the list by status', function (): void {
    $issued = DesktopLicenseSale::factory()->create(['status' => LicenseSaleStatus::Issued]);
    $failed = DesktopLicenseSale::factory()->create(['status' => LicenseSaleStatus::Failed]);

    livewire(ListDesktopLicenseSales::class)
        ->filterTable('status', LicenseSaleStatus::Issued->value)
        ->assertCanSeeTableRecords([$issued])
        ->assertCanNotSeeTableRecords([$failed]);
});

it('shows the full key and raw response on the view page', function (): void {
    $sale = DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Issued,
        'license_key' => 'abc.def',
        'raw_response' => ['data' => ['status' => 'success']],
    ]);

    livewire(ViewDesktopLicenseSale::class, ['record' => $sale->id])
        ->assertOk()
        ->assertSee('abc.def');
});

it('denies non-super-admins access to the license sales list', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create();
    $this->actingAs($manager);

    expect($manager->canAccessPanel(Filament::getPanel('superadmin')))->toBeFalse();
});
