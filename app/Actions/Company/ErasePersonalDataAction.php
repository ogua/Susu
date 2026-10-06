<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Right-to-erasure for an offboarded company (Data Protection Act, 2012 —
 * Act 843): once it has been archived for longer than the retention period,
 * every customer's and staff member's identifying data is anonymised and
 * KYC documents and photos deleted. Financial records (accounts, loans,
 * ledger) are kept, pointing at anonymised people, so the books still
 * balance. Irreversible; take an export first.
 */
class ErasePersonalDataAction
{
    /** Customer columns blanked on erasure (names and phone are replaced, not nulled — they are required). */
    private const CUSTOMER_PERSONAL_COLUMNS = [
        'other_names', 'email', 'gender', 'date_of_birth', 'id_number', 'id_photo_path', 'photo_path',
        'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship', 'address', 'external_id',
        'place_of_birth', 'nationality', 'city_town', 'state_region', 'country', 'digital_address',
        'latitude', 'longitude', 'marital_status', 'spouse_name', 'spouse_date_of_birth', 'spouse_occupation',
        'past_loan_institution', 'spouse_employer_name', 'spouse_employer_address', 'spouse_employer_town',
        'spouse_employer_county', 'spouse_employer_region', 'religion', 'business_name', 'business_phone',
        'business_tin', 'business_address', 'business_town', 'business_county', 'business_region',
        'business_latitude', 'business_longitude', 'tin', 'occupation', 'job_title', 'country_of_residence',
        'residence_permit',
    ];

    public static function eligibleFrom(Company $company): ?CarbonInterface
    {
        return $company->archived_at?->copy()->addDays((int) config('platform.data_retention_days'));
    }

    public function canErase(Company $company): bool
    {
        $eligibleFrom = self::eligibleFrom($company);

        return $company->personal_data_erased_at === null && $eligibleFrom !== null && $eligibleFrom->isPast();
    }

    /**
     * @return array{customers: int, users: int}
     */
    public function execute(Company $company): array
    {
        if (! $this->canErase($company)) {
            throw ValidationException::withMessages([
                'company' => 'Personal data can only be erased for a company archived longer than the retention period.',
            ]);
        }

        return DB::transaction(function () use ($company): array {
            $customers = 0;

            Customer::withTrashed()->where('company_id', $company->id)->chunkById(200, function ($chunk) use (&$customers): void {
                foreach ($chunk as $customer) {
                    $this->deleteFiles('local', [$customer->id_photo_path, $customer->photo_path]);
                    $this->deleteFiles('public', [$customer->photo_path]);

                    $customer->identifications()->delete();
                    $customer->familyMembers()->delete();
                    $customer->beneficiaries()->delete();

                    $customer->forceFill([
                        ...array_fill_keys(self::CUSTOMER_PERSONAL_COLUMNS, null),
                        'first_name' => 'Erased',
                        'last_name' => 'Customer',
                        'phone' => 'ERASED-'.substr($customer->id, 0, 8),
                    ])->saveQuietly();

                    $customers++;
                }
            });

            $users = 0;

            User::query()->where('company_id', $company->id)->chunkById(200, function ($chunk) use (&$users): void {
                foreach ($chunk as $user) {
                    $this->deleteFiles('public', [$user->photo_path]);
                    $user->tokens()->delete();

                    $user->forceFill([
                        'name' => 'Erased user',
                        'email' => 'erased-'.$user->id.'@invalid.local',
                        'phone' => null,
                        'photo_path' => null,
                        'is_active' => false,
                        'app_authentication_secret' => null,
                        'app_authentication_recovery_codes' => null,
                    ])->saveQuietly();

                    $users++;
                }
            });

            $company->update(['personal_data_erased_at' => now()]);

            activity('offboarding')
                ->performedOn($company)
                ->event('personal_data_erased')
                ->withProperties(['customers' => $customers, 'users' => $users])
                ->log("Personal data erased for {$company->name}");

            return ['customers' => $customers, 'users' => $users];
        });
    }

    /**
     * @param  array<int, ?string>  $paths
     */
    private function deleteFiles(string $disk, array $paths): void
    {
        foreach (array_filter($paths) as $path) {
            Storage::disk($disk)->delete($path);
        }
    }
}
