<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One dispatch point for signed report downloads. The API's
 * ReportController::downloadUrl() mints short-lived signed URLs onto this
 * route (auth = the signature, validated by the 'signed' middleware, minted
 * only after the API's role checks); each report controller's own
 * authorize step steps aside for a valid signature.
 */
class SignedReportDownloadController extends Controller
{
    private const CONTROLLERS = [
        'trial-balance' => TrialBalanceReportController::class,
        'defaulters' => DefaultersReportController::class,
        'cash-position' => CashPositionReportController::class,
        'collections' => CollectionsReportController::class,
        'loan-portfolio' => LoanPortfolioReportController::class,
        'agent-performance' => AgentPerformanceReportController::class,
        'withdrawals' => WithdrawalsReportController::class,
        'groups' => GroupReportController::class,
        'customer-balances' => CustomerBalancesReportController::class,
        'general-ledger' => GeneralLedgerReportController::class,
        'income-statement' => IncomeStatementReportController::class,
        'balance-sheet' => BalanceSheetReportController::class,
    ];

    public function __invoke(Request $request, Branch $branch, string $report, string $format): Response
    {
        abort_unless(isset(self::CONTROLLERS[$report]), 404);
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);

        $controller = app(self::CONTROLLERS[$report]);

        return $format === 'pdf'
            ? $controller->pdf($request, $branch)
            : $controller->excel($request, $branch);
    }
}
