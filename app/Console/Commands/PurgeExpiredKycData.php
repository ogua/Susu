<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Redacts KYC identification data for customers whose record was deleted
 * more than the retention window ago (AD-16 compliance). The customer row
 * itself is kept — savings accounts, loans, and journal entries reference
 * customer_id for the audit trail — only the sensitive identity fields
 * (Ghana Card number + the two private-disk photos) are scrubbed.
 */
class PurgeExpiredKycData extends Command
{
    protected $signature = 'kyc:purge-expired {--days=90 : Days after deletion before KYC data is purged}';

    protected $description = 'Redacts ID number and photos for customers deleted longer than the retention window ago.';

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

                    $customer->forceFill([
                        'id_number' => null,
                        'id_photo_path' => null,
                        'photo_path' => null,
                    ])->saveQuietly();

                    $purged++;
                }
            });

        $this->info("Purged KYC data for {$purged} customer(s).");

        return self::SUCCESS;
    }
}
