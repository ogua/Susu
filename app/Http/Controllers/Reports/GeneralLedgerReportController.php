<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\BuildGeneralLedgerAction;
use App\Exports\GeneralLedgerExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ResolvesReportRequest;
use App\Models\Branch;
use App\Models\LedgerAccount;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Company-wide like the trial balance (system accounts carry no branch_id);
 * the {branch} segment scopes authorization, not the data.
 */
class GeneralLedgerReportController extends Controller
{
    use ResolvesReportRequest;

    public function pdf(Request $request, Branch $branch): Response
    {
        $this->authorizeStaffAccess($request, $branch);
        [$from, $to] = $this->dateRange($request);

        $result = app(BuildGeneralLedgerAction::class)
            ->execute($branch->company, $this->account($request, $branch), $from, $to);

        return Pdf::loadView('pdf.general-ledger', [
            'company' => $branch->company,
            ...$result,
        ])->download('general-ledger.pdf');
    }

    public function excel(Request $request, Branch $branch): BinaryFileResponse
    {
        $this->authorizeStaffAccess($request, $branch);
        [$from, $to] = $this->dateRange($request);

        return Excel::download(
            new GeneralLedgerExport($branch->company, $this->account($request, $branch), $from, $to),
            'general-ledger.xlsx',
        );
    }

    private function account(Request $request, Branch $branch): ?LedgerAccount
    {
        $validated = $request->validate([
            'account_id' => ['nullable', 'uuid'],
        ]);

        if (! isset($validated['account_id'])) {
            return null;
        }

        return LedgerAccount::query()
            ->where('company_id', $branch->company_id)
            ->findOrFail($validated['account_id']);
    }
}
