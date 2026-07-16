<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\BuildGroupReportAction;
use App\Exports\GroupReportExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ResolvesReportRequest;
use App\Models\Branch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GroupReportController extends Controller
{
    use ResolvesReportRequest;

    public function pdf(Request $request, Branch $branch): Response
    {
        $this->authorizeStaffAccess($request, $branch);
        [$from, $to] = $this->dateRange($request);

        $result = app(BuildGroupReportAction::class)->execute($branch, $from, $to);

        return Pdf::loadView('pdf.group-report', [
            'branch' => $branch,
            ...$result,
        ])->download('group-report.pdf');
    }

    public function excel(Request $request, Branch $branch): BinaryFileResponse
    {
        $this->authorizeStaffAccess($request, $branch);
        [$from, $to] = $this->dateRange($request);

        return Excel::download(new GroupReportExport($branch, $from, $to), 'group-report.xlsx');
    }
}
