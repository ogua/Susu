<?php

namespace App\Http\Controllers;

use App\Models\CompanyExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Downloads a finished company data export — super admins only (it holds every customer's data). */
class CompanyExportDownloadController extends Controller
{
    public function __invoke(Request $request, CompanyExport $export): StreamedResponse
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403);
        abort_unless($export->status === CompanyExport::STATUS_READY && $export->path !== null, 404);

        activity('offboarding')
            ->causedBy($request->user())
            ->performedOn($export->company)
            ->event('export_downloaded')
            ->log("Data export downloaded for {$export->company->name}");

        return Storage::disk('local')->download($export->path);
    }
}
