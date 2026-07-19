<?php

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerIdentification;
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

it('creates an individual customer through the wizard including identifications and beneficiaries', function (): void {
    bootAdminPanelWithTenant($this->branch);
    request()->merge(['client_type' => 'individual']);

    livewire(CreateCustomer::class)
        ->fillForm([
            'client_type' => 'individual',
            'first_name' => 'Ama',
            'last_name' => 'Serwaa',
            'phone' => '+233244000111',
            'identifications' => [
                [
                    'id_type' => 'ghana_card',
                    'id_number' => 'GHA-000123456-7',
                    'issue_date' => '2026-01-15',
                    'is_primary' => true,
                ],
            ],
            'beneficiaries' => [
                [
                    'name' => 'Kwame Serwaa',
                    'relationship' => 'son',
                    'amount_of_legacy' => 500,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $customer = Customer::where('phone', '+233244000111')->firstOrFail();

    expect($customer->client_type->value)->toBe('individual')
        ->and($customer->id_type)->toBe('ghana_card')
        ->and($customer->id_number)->toBe('GHA-000123456-7')
        ->and($customer->identifications)->toHaveCount(1)
        ->and($customer->identifications->first()->is_primary)->toBeTrue()
        ->and($customer->beneficiaries)->toHaveCount(1)
        ->and($customer->beneficiaries->first()->name)->toBe('Kwame Serwaa')
        ->and($customer->beneficiaries->first()->amount_of_legacy)->toBe(50000);
});

it('creates a business customer via the business-only wizard steps', function (): void {
    bootAdminPanelWithTenant($this->branch);
    request()->merge(['client_type' => 'business']);

    livewire(CreateCustomer::class)
        ->fillForm([
            'client_type' => 'business',
            'business_name' => 'Kasapreko Traders Ltd',
            'business_structure' => 'limited_liability_company',
            'business_start_date' => '2020-06-01',
            'phone' => '+233302123456',
            'first_name' => 'Yaw',
            'last_name' => 'Boateng',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $customer = Customer::where('phone', '+233302123456')->firstOrFail();

    expect($customer->client_type->value)->toBe('business')
        ->and($customer->business_name)->toBe('Kasapreko Traders Ltd')
        ->and($customer->first_name)->toBe('Yaw')
        ->and($customer->last_name)->toBe('Boateng')
        // Business clients never went through the Individual-only Identification
        // step, so no identification rows and no legacy id_type/id_number mirror.
        ->and($customer->identifications)->toHaveCount(0)
        ->and($customer->id_number)->toBeNull();
});

it('requires business_name/business_structure/business_start_date on the business wizard', function (): void {
    bootAdminPanelWithTenant($this->branch);
    request()->merge(['client_type' => 'business']);

    livewire(CreateCustomer::class)
        ->fillForm([
            'client_type' => 'business',
            'phone' => '+233302999888',
        ])
        ->call('create')
        ->assertHasFormErrors();

    expect(Customer::where('phone', '+233302999888')->exists())->toBeFalse();
});

it('reconciles identification rows on edit, adding one and removing another', function (): void {
    bootAdminPanelWithTenant($this->branch);

    $customer = Customer::factory()->individual()->forBranch($this->branch)->create();
    $kept = CustomerIdentification::factory()->for($customer)->create(['id_type' => 'ghana_card', 'is_primary' => true]);
    $removed = CustomerIdentification::factory()->for($customer)->create(['id_type' => 'passport']);

    livewire(EditCustomer::class, ['record' => $customer->getKey()])
        ->fillForm([
            'identifications' => [
                [
                    'id' => $kept->id,
                    'id_type' => 'ghana_card',
                    'id_number' => $kept->id_number,
                    'issue_date' => $kept->issue_date?->toDateString(),
                    'is_primary' => true,
                ],
                [
                    'id_type' => 'voters_id',
                    'id_number' => 'VOT-999999',
                    'issue_date' => '2024-01-01',
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $customer->refresh();

    expect(CustomerIdentification::find($removed->id))->toBeNull()
        ->and(CustomerIdentification::find($kept->id))->not->toBeNull()
        ->and($customer->identifications()->where('id_type', 'voters_id')->exists())->toBeTrue()
        ->and($customer->identifications)->toHaveCount(2);
});
