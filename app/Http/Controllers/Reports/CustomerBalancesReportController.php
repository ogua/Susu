<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\BuildCustomerBalancesAction;
use App\Exports\CustomerBalancesExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ResolvesReportRequest;
use App\Models\Branch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomerBalancesReportController extends Controller
{
    use ResolvesReportRequest;

    public function pdf(Request $request, Branch $branch): Response
    {
        $this->authorizeStaffAccess($request, $branch);

        $result = app(BuildCustomerBalancesAction::class)->execute($branch);

        return Pdf::loadView('pdf.customer-balances', [
            'branch' => $branch,
            ...$result,
        ])->download('customer-balances.pdf');
    }

    public function excel(Request $request, Branch $branch): BinaryFileResponse
    {
        $this->authorizeStaffAccess($request, $branch);

        return Excel::download(new CustomerBalancesExport($branch), 'customer-balances.xlsx');
    }
}
