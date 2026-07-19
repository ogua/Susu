<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBeneficiary;
use App\Models\CustomerFamilyMember;
use App\Models\CustomerIdentification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->branch = Branch::factory()->create();
});

it('redacts id number and photos for a customer deleted past the retention window', function (): void {
    $idPhoto = Storage::disk('local')->putFile('customers/id-photos', UploadedFile::fake()->image('id.jpg'));
    $photo = Storage::disk('local')->putFile('customers/photos', UploadedFile::fake()->image('photo.jpg'));

    $customer = Customer::factory()->forBranch($this->branch)->create([
        'id_number' => 'GHA-123456789-0',
        'id_photo_path' => $idPhoto,
        'photo_path' => $photo,
    ]);
    $customer->delete();
    $customer->forceFill(['deleted_at' => now()->subDays(91)])->saveQuietly();

    $this->artisan('kyc:purge-expired')->assertSuccessful();

    $fresh = Customer::onlyTrashed()->findOrFail($customer->id);
    expect($fresh->id_number)->toBeNull()
        ->and($fresh->id_photo_path)->toBeNull()
        ->and($fresh->photo_path)->toBeNull();

    Storage::disk('local')->assertMissing($idPhoto);
    Storage::disk('local')->assertMissing($photo);
});

it('leaves a customer deleted within the retention window untouched', function (): void {
    $customer = Customer::factory()->forBranch($this->branch)->create(['id_number' => 'GHA-123456789-0']);
    $customer->delete();
    $customer->forceFill(['deleted_at' => now()->subDays(10)])->saveQuietly();

    $this->artisan('kyc:purge-expired')->assertSuccessful();

    expect(Customer::onlyTrashed()->findOrFail($customer->id)->id_number)->not->toBeNull();
});

it('leaves an active (non-deleted) customer untouched', function (): void {
    $customer = Customer::factory()->forBranch($this->branch)->create(['id_number' => 'GHA-123456789-0']);

    $this->artisan('kyc:purge-expired')->assertSuccessful();

    expect($customer->fresh()->id_number)->not->toBeNull();
});

it('is idempotent on a second run', function (): void {
    $customer = Customer::factory()->forBranch($this->branch)->create(['id_number' => 'GHA-123456789-0']);
    $customer->delete();
    $customer->forceFill(['deleted_at' => now()->subDays(91)])->saveQuietly();

    $this->artisan('kyc:purge-expired')->assertSuccessful();
    $this->artisan('kyc:purge-expired')->assertSuccessful();

    expect(Customer::onlyTrashed()->findOrFail($customer->id)->id_number)->toBeNull();
});

it('deletes identification and family-member rows and nulls tin/business_tin/religion/spouse fields for a customer past the retention window', function (): void {
    $customer = Customer::factory()->forBranch($this->branch)->create([
        'id_number' => 'GHA-123456789-0',
        'tin' => 'TIN-001',
        'business_tin' => 'BTIN-002',
        'religion' => 'Christian',
        'spouse_name' => 'Ama Mensah',
        'spouse_date_of_birth' => '1990-01-01',
        'spouse_occupation' => 'Trader',
        'spouse_employer_name' => 'ABC Ltd',
        'spouse_employer_address' => '123 Main St',
        'spouse_employer_town' => 'Accra',
        'spouse_employer_county' => 'Greater Accra',
        'spouse_employer_region' => 'Greater Accra',
        'past_loan_institution' => 'XYZ Bank',
    ]);
    $identification = CustomerIdentification::factory()->for($customer)->create();
    $familyMember = CustomerFamilyMember::factory()->for($customer)->create();

    $customer->delete();
    $customer->forceFill(['deleted_at' => now()->subDays(91)])->saveQuietly();

    $this->artisan('kyc:purge-expired')->assertSuccessful();

    $fresh = Customer::onlyTrashed()->findOrFail($customer->id);
    expect($fresh->tin)->toBeNull()
        ->and($fresh->business_tin)->toBeNull()
        ->and($fresh->religion)->toBeNull()
        ->and($fresh->spouse_name)->toBeNull()
        ->and($fresh->spouse_date_of_birth)->toBeNull()
        ->and($fresh->spouse_occupation)->toBeNull()
        ->and($fresh->spouse_employer_name)->toBeNull()
        ->and($fresh->spouse_employer_address)->toBeNull()
        ->and($fresh->spouse_employer_town)->toBeNull()
        ->and($fresh->spouse_employer_county)->toBeNull()
        ->and($fresh->spouse_employer_region)->toBeNull()
        ->and($fresh->past_loan_institution)->toBeNull();

    expect(CustomerIdentification::find($identification->id))->toBeNull()
        ->and(CustomerFamilyMember::find($familyMember->id))->toBeNull();
});

it('leaves beneficiaries untouched after purge', function (): void {
    $customer = Customer::factory()->forBranch($this->branch)->create(['id_number' => 'GHA-123456789-0']);
    $beneficiary = CustomerBeneficiary::factory()->for($customer)->create();

    $customer->delete();
    $customer->forceFill(['deleted_at' => now()->subDays(91)])->saveQuietly();

    $this->artisan('kyc:purge-expired')->assertSuccessful();

    expect(CustomerBeneficiary::find($beneficiary->id))->not->toBeNull();
});

it('respects a custom retention window via the --days option', function (): void {
    $customer = Customer::factory()->forBranch($this->branch)->create(['id_number' => 'GHA-123456789-0']);
    $customer->delete();
    $customer->forceFill(['deleted_at' => now()->subDays(10)])->saveQuietly();

    $this->artisan('kyc:purge-expired', ['--days' => 5])->assertSuccessful();

    expect(Customer::onlyTrashed()->findOrFail($customer->id)->id_number)->toBeNull();
});
