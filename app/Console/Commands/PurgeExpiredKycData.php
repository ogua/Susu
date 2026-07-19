<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Redacts KYC identification data for customers whose record was deleted
 * more than the retention window ago (AD-16 compliance). The customer row
 * itself is kept — savings accounts, loans, and journal entries reference
 * customer_id for the audit trail — only the sensitive identity fields are
 * scrubbed: the legacy Ghana Card number + the two private-disk photos, every
 * customer_identifications row (additional ID documents), every
 * customer_family_members row, and the TIN/religion/spouse cluster on the
 * customer row. customer_beneficiaries is deliberately NOT purged, same as
 * next_of_kin_name/address — it serves a continuing legal/audit purpose
 * rather than being an identity document.
 */
class PurgeExpiredKycData extends Command
{
    protected $signature = 'kyc:purge-expired {--days=90 : Days after deletion before KYC data is purged}';

    protected $description = 'Redacts identification data for customers deleted longer than the retention window ago.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $purged = 0;

        Customer::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays($days))
            // Idempotency guard: a record with no id_number left has already
            // been purged, so a daily re-run only ever touches new arrivals.
            ->whereNotNull('id_number')
            ->chunkById(200, function ($customers) use (&$purged): void {
                foreach ($customers as $customer) {
                    foreach ([$customer->id_photo_path, $customer->photo_path] as $path) {
                        if ($path) {
                            Storage::disk('local')->delete($path);
                        }
                    }

                    $customer->identifications()->delete();
                    $customer->familyMembers()->delete();

                    $customer->forceFill([
                        'id_number' => null,
                        'id_photo_path' => null,
                        'photo_path' => null,
                        'tin' => null,
                        'business_tin' => null,
                        'religion' => null,
                        'spouse_name' => null,
                        'spouse_date_of_birth' => null,
                        'spouse_occupation' => null,
                        'spouse_employer_name' => null,
                        'spouse_employer_address' => null,
                        'spouse_employer_town' => null,
                        'spouse_employer_county' => null,
                        'spouse_employer_region' => null,
                        'past_loan_institution' => null,
                    ])->saveQuietly();

                    $purged++;
                }
            });

        $this->info("Purged KYC data for {$purged} customer(s).");

        return self::SUCCESS;
    }
}
