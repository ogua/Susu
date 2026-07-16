<?php

use App\Filament\SuperAdmin\Resources\Users\Pages\CreateUser;
use App\Filament\SuperAdmin\Resources\Users\Pages\ListUsers;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;
use STS\FilamentImpersonate\Facades\Impersonation;

beforeEach(function (): void {
    seedRoles();
    $this->superAdmin = User::factory()->superAdmin()->create();
    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();
});

it('lists staff users across every company, with no tenant leakage between them', function (): void {
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    $managerA = User::factory()->branchManager($branchA)->create();
    $managerB = User::factory()->branchManager($branchB)->create();

    livewire(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$managerA, $managerB]);
});

it('excludes customer-role users from the list', function (): void {
    $company = Company::factory()->create();
    $customer = User::factory()->customerUser($company)->create();

    livewire(ListUsers::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$customer]);
});

it('lets a super admin create a user with company, branches, and roles', function (): void {
    $branch = Branch::factory()->create();
    $role = Role::where('name', 'branch_manager')->firstOrFail();

    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'New Manager',
            'email' => 'new-manager@example.com',
            'password' => 'password123',
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'branches' => [$branch->id],
            'roles' => [$role->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'new-manager@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->hasRole('branch_manager'))->toBeTrue()
        ->and($user->branches->pluck('id'))->toContain($branch->id);
});

it('lets a super admin impersonate a branch manager and land on their admin panel', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create();

    livewire(ListUsers::class)
        ->callTableAction('impersonate', $manager);

    expect(Impersonation::isImpersonating())->toBeTrue()
        ->and(auth()->user()->is($manager))->toBeTrue();
});

it('does not show the impersonate action for another super admin', function (): void {
    $otherSuperAdmin = User::factory()->superAdmin()->create();

    livewire(ListUsers::class)
        ->assertTableActionHidden('impersonate', $otherSuperAdmin);
});

it('denies non-super-admins access to the users list', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create();
    $this->actingAs($manager);

    expect($manager->canAccessPanel(Filament::getPanel('superadmin')))->toBeFalse();
});
