<?php

namespace App\Jobs;

use App\Actions\Company\ExportCompanyDataAction;
use App\Models\CompanyExport;
use App\Notifications\PlatformAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Builds a company data export in the background — large companies take minutes. */
class GenerateCompanyExportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public CompanyExport $export) {}

    public function handle(ExportCompanyDataAction $exportCompanyData): void
    {
        $export = $exportCompanyData->build($this->export);

        if ($export->status === CompanyExport::STATUS_FAILED) {
            PlatformAlert::toSuperAdmins(
                'Data export failed',
                "The data export for {$export->company->name} failed: {$export->error}",
            );
        }
    }
}
