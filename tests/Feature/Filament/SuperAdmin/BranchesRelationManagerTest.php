<?php

use App\Filament\SuperAdmin\Resources\Companies\Pages\EditCompany;
use App\Filament\SuperAdmin\Resources\Companies\RelationManagers\BranchesRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();
    $this->superAdmin = User::factory()->superAdmin()->create();
    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();
    $this->company = Company::factory()->create();
});

it('lists only branches belonging to the owning company', function (): void {
    $mine = Branch::factory()->create(['company_id' => $this->company->id]);
    $otherCompanyBranch = Branch::factory()->create();

    livewire(BranchesRelationManager::class, [
        'ownerRecord' => $this->company,
        'pageClass' => EditCompany::class,
    ])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$otherCompanyBranch]);
});

it('creates a branch nested under the company, without needing to set company_id manually', function (): void {
    livewire(BranchesRelationManager::class, [
        'ownerRecord' => $this->company,
        'pageClass' => EditCompany::class,
    ])
        ->callTableAction('create', data: [
            'name' => 'Kumasi Branch',
            'slug' => 'kumasi',
            'code' => 'KUM',
            'contact_phone' => '+233244000000',
            'contact_email' => 'kumasi@example.com',
        ])
        ->assertHasNoTableActionErrors();

    $branch = Branch::where('slug', 'kumasi')->first();
    expect($branch)->not->toBeNull()
        ->and($branch->company_id)->toBe($this->company->id);
});

it('rejects a duplicate slug within the same company but allows it across companies', function (): void {
    Branch::factory()->create(['company_id' => $this->company->id, 'slug' => 'main']);
    $otherCompany = Company::factory()->create();
    Branch::factory()->create(['company_id' => $otherCompany->id, 'slug' => 'main']);

    livewire(BranchesRelationManager::class, [
        'ownerRecord' => $this->company,
        'pageClass' => EditCompany::class,
    ])
        ->callTableAction('create', data: [
            'name' => 'Duplicate',
            'slug' => 'main',
        ])
        ->assertHasTableActionErrors(['slug']);
});
