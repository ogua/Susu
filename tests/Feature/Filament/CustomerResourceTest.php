<?php

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = User::factory()->companyAdmin($this->branch->company)->create();
    $this->admin->branches()->syncWithoutDetaching([$this->branch->id]);
    $this->actingAs($this->admin);
});

it('lists customers scoped to the tenant branch for a branch manager', function (): void {
    $manager = User::factory()->branchManager($this->branch)->create();
    $this->actingAs($manager);

    $mine = Customer::factory()->forBranch($this->branch)->create();
    $otherBranch = Branch::factory()->create(['company_id' => $this->branch->company_id]);
    Customer::factory()->forBranch($otherBranch)->create();

    bootAdminPanelWithTenant($this->branch);

    livewire(ListCustomers::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCountTableRecords(1);
});

it('lets a company admin see customers across every branch in their company', function (): void {
    $mine = Customer::factory()->forBranch($this->branch)->create();
    $otherBranch = Branch::factory()->create(['company_id' => $this->branch->company_id]);
    $sameCompany = Customer::factory()->forBranch($otherBranch)->create();

    $otherCompanyBranch = Branch::factory()->create();
    Customer::factory()->forBranch($otherCompanyBranch)->create();

    bootAdminPanelWithTenant($this->branch);

    livewire(ListCustomers::class)
        ->assertCanSeeTableRecords([$mine, $sameCompany])
        ->assertCountTableRecords(2);
});

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

it('creates a customer through CreateCustomerAction, auto-assigning code and branch', function (): void {
    bootAdminPanelWithTenant($this->branch);

    livewire(CreateCustomer::class)
        ->fillForm([
            'first_name' => 'Ama',
            'last_name' => 'Mensah',
            'phone' => '+233241112222',
            'id_type' => 'ghana_card',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $customer = Customer::where('phone', '+233241112222')->first();

    expect($customer)->not->toBeNull()
        ->and($customer->branch_id)->toBe($this->branch->id)
        ->and($customer->customer_code)->not->toBeNull();
});
