<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\BuildWithdrawalsReportAction;
use App\Enums\WithdrawalStatus;
use App\Exports\WithdrawalsReportExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ResolvesReportRequest;
use App\Models\Branch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WithdrawalsReportController extends Controller
{
    use ResolvesReportRequest;

    public function pdf(Request $request, Branch $branch): Response
    {
        $this->authorizeStaffAccess($request, $branch);
        [$from, $to] = $this->dateRange($request);

        $result = app(BuildWithdrawalsReportAction::class)
            ->execute($branch, $from, $to, $this->status($request));

        return Pdf::loadView('pdf.withdrawals-report', [
            'branch' => $branch,
            ...$result,
        ])->download('withdrawals-report.pdf');
    }

    public function excel(Request $request, Branch $branch): BinaryFileResponse
    {
        $this->authorizeStaffAccess($request, $branch);
        [$from, $to] = $this->dateRange($request);

        return Excel::download(
            new WithdrawalsReportExport($branch, $from, $to, $this->status($request)),
            'withdrawals-report.xlsx',
        );
    }

    private function status(Request $request): ?WithdrawalStatus
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(WithdrawalStatus::class)],
        ]);

        return isset($validated['status']) ? WithdrawalStatus::from($validated['status']) : null;
    }
}
