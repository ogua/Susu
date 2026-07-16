<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\BuildLoanPortfolioReportAction;
use App\Enums\LoanStatus;
use App\Exports\LoanPortfolioExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ResolvesReportRequest;
use App\Models\Branch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LoanPortfolioReportController extends Controller
{
    use ResolvesReportRequest;

    public function pdf(Request $request, Branch $branch): Response
    {
        $this->authorizeStaffAccess($request, $branch);
        [$from, $to] = $this->dateRange($request);

        $result = app(BuildLoanPortfolioReportAction::class)
            ->execute($branch, $from, $to, $this->status($request));

        return Pdf::loadView('pdf.loan-portfolio', [
            'branch' => $branch,
            ...$result,
        ])->download('loan-portfolio.pdf');
    }

    public function excel(Request $request, Branch $branch): BinaryFileResponse
    {
        $this->authorizeStaffAccess($request, $branch);
        [$from, $to] = $this->dateRange($request);

        return Excel::download(
            new LoanPortfolioExport($branch, $from, $to, $this->status($request)),
            'loan-portfolio.xlsx',
        );
    }

    private function status(Request $request): ?LoanStatus
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(LoanStatus::class)],
        ]);

        return isset($validated['status']) ? LoanStatus::from($validated['status']) : null;
    }
}
