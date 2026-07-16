<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\BuildCollectionsReportAction;
use App\Exports\CollectionsReportExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ResolvesReportRequest;
use App\Models\Branch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CollectionsReportController extends Controller
{
    use ResolvesReportRequest;

    public function pdf(Request $request, Branch $branch): Response
    {
        $this->authorizeStaffAccess($request, $branch);
        [$from, $to] = $this->dateRange($request);

        $result = app(BuildCollectionsReportAction::class)->execute($branch, $from, $to);

        return Pdf::loadView('pdf.collections-report', [
            'branch' => $branch,
            ...$result,
        ])->download('collections-report.pdf');
    }

    public function excel(Request $request, Branch $branch): BinaryFileResponse
    {
        $this->authorizeStaffAccess($request, $branch);
        [$from, $to] = $this->dateRange($request);

        return Excel::download(new CollectionsReportExport($branch, $from, $to), 'collections-report.xlsx');
    }
}
