<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\BuildCashPositionAction;
use App\Exports\CashPositionExport;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CashPositionReportController extends Controller
{
    public function pdf(Request $request, Branch $branch): Response
    {
        $this->authorizeAccess($request, $branch);

        $result = app(BuildCashPositionAction::class)->execute($branch);

        return Pdf::loadView('pdf.cash-position', [
            'branch' => $branch,
            ...$result,
        ])->download('cash-position.pdf');
    }

    public function excel(Request $request, Branch $branch): BinaryFileResponse
    {
        $this->authorizeAccess($request, $branch);

        return Excel::download(new CashPositionExport($branch), 'cash-position.xlsx');
    }

    private function authorizeAccess(Request $request, Branch $branch): void
    {
        $user = $request->user();
        abort_unless($user->hasRole(['company_admin', 'branch_manager']), 403);

        $allowed = $user->hasRole('company_admin')
            ? $user->company_id === $branch->company_id
            : $user->branch_id === $branch->id;

        abort_unless($allowed, 404);
    }
}
