<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\BuildCompanyUsageReportAction;
use App\Exports\CompanyUsageExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ResolvesReportRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Platform-wide tenant usage — super admins only. */
class CompanyUsageReportController extends Controller
{
    use ResolvesReportRequest;

    public function pdf(Request $request): Response
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403);
        [$from, $to] = $this->period($request);

        return Pdf::loadView('pdf.company-usage', [
            'companies' => app(BuildCompanyUsageReportAction::class)->execute($from, $to),
            'from' => $from,
            'to' => $to,
        ])->setPaper('a4', 'landscape')->download('company-usage.pdf');
    }

    public function excel(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403);
        [$from, $to] = $this->period($request);

        return Excel::download(new CompanyUsageExport($from, $to), 'company-usage.xlsx');
    }

    /**
     * Defaults to month-to-date, matching the on-screen report.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        [$from, $to] = $this->dateRange($request);

        return [$from ?? CarbonImmutable::now()->startOfMonth(), $to ?? CarbonImmutable::now()];
    }
}
