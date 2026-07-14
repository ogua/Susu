<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\BuildTrialBalanceAction;
use App\Exports\TrialBalanceExport;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TrialBalanceReportController extends Controller
{
    public function pdf(Request $request, Branch $branch): Response
    {
        $this->authorizeAccess($request, $branch);

        $result = app(BuildTrialBalanceAction::class)->execute($branch->company);

        return Pdf::loadView('pdf.trial-balance', [
            'company' => $branch->company,
            ...$result,
        ])->download('trial-balance.pdf');
    }

    public function excel(Request $request, Branch $branch): BinaryFileResponse
    {
        $this->authorizeAccess($request, $branch);

        return Excel::download(new TrialBalanceExport($branch->company), 'trial-balance.xlsx');
    }

    private function authorizeAccess(Request $request, Branch $branch): void
    {
        $user = $request->user();
        abort_unless($user->hasRole(['company_admin', 'branch_manager']), 403);
        abort_unless($user->company_id === $branch->company_id, 404);
    }
}
