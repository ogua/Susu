<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\BuildIncomeStatementAction;
use App\Exports\IncomeStatementExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ResolvesReportRequest;
use App\Models\Branch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IncomeStatementReportController extends Controller
{
    use ResolvesReportRequest;

    public function pdf(Request $request, Branch $branch): Response
    {
        $this->authorizeStaffAccess($request, $branch);
        [$from, $to] = $this->dateRange($request);

        $result = app(BuildIncomeStatementAction::class)->execute($branch->company, $from, $to);

        return Pdf::loadView('pdf.income-statement', [
            'company' => $branch->company,
            ...$result,
        ])->download('income-statement.pdf');
    }

    public function excel(Request $request, Branch $branch): BinaryFileResponse
    {
        $this->authorizeStaffAccess($request, $branch);
        [$from, $to] = $this->dateRange($request);

        return Excel::download(new IncomeStatementExport($branch->company, $from, $to), 'income-statement.xlsx');
    }
}
