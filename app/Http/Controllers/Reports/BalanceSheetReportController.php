<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\BuildBalanceSheetAction;
use App\Exports\BalanceSheetExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ResolvesReportRequest;
use App\Models\Branch;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BalanceSheetReportController extends Controller
{
    use ResolvesReportRequest;

    public function pdf(Request $request, Branch $branch): Response
    {
        $this->authorizeStaffAccess($request, $branch);

        $result = app(BuildBalanceSheetAction::class)->execute($branch->company, $this->asAt($request));

        return Pdf::loadView('pdf.balance-sheet', [
            'company' => $branch->company,
            ...$result,
        ])->download('balance-sheet.pdf');
    }

    public function excel(Request $request, Branch $branch): BinaryFileResponse
    {
        $this->authorizeStaffAccess($request, $branch);

        return Excel::download(new BalanceSheetExport($branch->company, $this->asAt($request)), 'balance-sheet.xlsx');
    }

    private function asAt(Request $request): ?CarbonImmutable
    {
        $validated = $request->validate([
            'as_at' => ['nullable', 'date'],
        ]);

        return isset($validated['as_at']) ? CarbonImmutable::parse($validated['as_at']) : null;
    }
}
