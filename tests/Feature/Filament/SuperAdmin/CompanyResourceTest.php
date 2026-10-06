<?php

use App\Filament\SuperAdmin\Resources\Companies\Pages\CreateCompany;
use App\Filament\SuperAdmin\Resources\Companies\Pages\ListCompanies;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();
    $this->superAdmin = User::factory()->superAdmin()->create();
});

it('lists every company platform-wide, with no tenant scoping', function (): void {
    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();

    $a = Company::factory()->create();
    $b = Company::factory()->create();

    livewire(ListCompanies::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$a, $b]);
});

it('lets a super admin create a company', function (): void {
    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();

    livewire(CreateCompany::class)
        ->fillForm([
            'name' => 'Kumasi Susu Ltd',
            'slug' => 'kumasi-susu',
            'domain_alias' => 'kumasi.susuapp.test',
            'contact_email' => 'ops@kumasi-susu.test',
            'contact_phone' => '+233244000000',
            'is_active' => true,
            'branch' => ['name' => 'Head Office', 'slug' => 'head-office'],
            'admin' => [
                'name' => 'Kumasi Admin',
                'email' => 'admin@kumasi-susu.test',
                'password' => 'secret-pass',
                'password_confirmation' => 'secret-pass',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Company::where('slug', 'kumasi-susu')->exists())->toBeTrue();
});

it('rejects a duplicate slug', function (): void {
    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();

    Company::factory()->create(['slug' => 'taken-slug']);

    livewire(CreateCompany::class)
        ->fillForm([
            'name' => 'Another Co',
            'slug' => 'taken-slug',
            'contact_email' => 'a@b.test',
            'contact_phone' => '+233244000001',
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('denies non-super-admins access to the companies list', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create();
    $this->actingAs($manager);

    expect($manager->canAccessPanel(Filament::getPanel('superadmin')))->toBeFalse();
});
