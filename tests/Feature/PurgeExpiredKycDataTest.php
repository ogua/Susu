<?php

use App\Models\Branch;
use App\Models\Customer;
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

it('respects a custom retention window via the --days option', function (): void {
    $customer = Customer::factory()->forBranch($this->branch)->create(['id_number' => 'GHA-123456789-0']);
    $customer->delete();
    $customer->forceFill(['deleted_at' => now()->subDays(10)])->saveQuietly();

    $this->artisan('kyc:purge-expired', ['--days' => 5])->assertSuccessful();

    expect(Customer::onlyTrashed()->findOrFail($customer->id)->id_number)->toBeNull();
});
