<?php

namespace App\Console\Commands;

use App\Models\CompanyExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes company data exports (zip + record) older than
 * platform.export_retention_days. An export holds every customer's personal
 * data, so it must not outlive its purpose; re-export if needed later.
 */
class PruneCompanyExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Deletes company data exports older than the export retention period.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('platform.export_retention_days'));
        $deleted = 0;

        CompanyExport::query()
            ->where('created_at', '<', $cutoff)
            ->with('company')
            ->each(function (CompanyExport $export) use (&$deleted): void {
                if ($export->path !== null) {
                    Storage::disk('local')->delete($export->path);
                }

                activity('offboarding')
                    ->performedOn($export->company)
                    ->event('export_pruned')
                    ->log("Data export from {$export->created_at->toDateString()} deleted after the retention period");

                $export->delete();
                $deleted++;
            });

        $this->info("Deleted {$deleted} expired export(s).");

        return self::SUCCESS;
    }
}
