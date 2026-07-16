<?php

use App\Http\Controllers\License\LicenseCheckoutController;
use App\Http\Controllers\Reports\AgentPerformanceReportController;
use App\Http\Controllers\Reports\BalanceSheetReportController;
use App\Http\Controllers\Reports\CashPositionReportController;
use App\Http\Controllers\Reports\CollectionsReportController;
use App\Http\Controllers\Reports\CustomerBalancesReportController;
use App\Http\Controllers\Reports\DefaultersReportController;
use App\Http\Controllers\Reports\GeneralLedgerReportController;
use App\Http\Controllers\Reports\GroupReportController;
use App\Http\Controllers\Reports\IncomeStatementReportController;
use App\Http\Controllers\Reports\LoanPortfolioReportController;
use App\Http\Controllers\Reports\TrialBalanceReportController;
use App\Http\Controllers\Reports\WithdrawalsReportController;
use App\Http\Controllers\SavingsAccountStatementController;
use App\Http\Controllers\SignedAccountStatementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/savings-accounts/{account}/statement', SavingsAccountStatementController::class)
    ->middleware('auth')
    ->name('savings-accounts.statement');

// Headerless download for the mobile app's in-app browser — see
// SignedAccountStatementController for why no auth middleware is needed.
Route::get('/statements/{account}/signed', SignedAccountStatementController::class)
    ->middleware('signed')
    ->name('statements.signed');

// Guest desktop-license purchase flow (see plan Phase 6) — the desktop app's
// activation screen links here; no auth, no Company context.
Route::middleware('throttle:20,1')->prefix('license')->name('license.')->group(function (): void {
    Route::get('/activate/{installId?}', [LicenseCheckoutController::class, 'showActivationForm'])->name('activate');
    Route::post('/checkout', [LicenseCheckoutController::class, 'initiateCheckout'])->name('checkout');
    Route::get('/callback', [LicenseCheckoutController::class, 'handleCallback'])->name('callback');
});

Route::middleware('auth')->prefix('reports/{branch}')->name('reports.')->group(function (): void {
    Route::get('/trial-balance.pdf', [TrialBalanceReportController::class, 'pdf'])->name('trial-balance.pdf');
    Route::get('/trial-balance.xlsx', [TrialBalanceReportController::class, 'excel'])->name('trial-balance.excel');
    Route::get('/defaulters.pdf', [DefaultersReportController::class, 'pdf'])->name('defaulters.pdf');
    Route::get('/defaulters.xlsx', [DefaultersReportController::class, 'excel'])->name('defaulters.excel');
    Route::get('/cash-position.pdf', [CashPositionReportController::class, 'pdf'])->name('cash-position.pdf');
    Route::get('/cash-position.xlsx', [CashPositionReportController::class, 'excel'])->name('cash-position.excel');
    Route::get('/collections.pdf', [CollectionsReportController::class, 'pdf'])->name('collections.pdf');
    Route::get('/collections.xlsx', [CollectionsReportController::class, 'excel'])->name('collections.excel');
    Route::get('/loan-portfolio.pdf', [LoanPortfolioReportController::class, 'pdf'])->name('loan-portfolio.pdf');
    Route::get('/loan-portfolio.xlsx', [LoanPortfolioReportController::class, 'excel'])->name('loan-portfolio.excel');
    Route::get('/agent-performance.pdf', [AgentPerformanceReportController::class, 'pdf'])->name('agent-performance.pdf');
    Route::get('/agent-performance.xlsx', [AgentPerformanceReportController::class, 'excel'])->name('agent-performance.excel');
    Route::get('/withdrawals.pdf', [WithdrawalsReportController::class, 'pdf'])->name('withdrawals.pdf');
    Route::get('/withdrawals.xlsx', [WithdrawalsReportController::class, 'excel'])->name('withdrawals.excel');
    Route::get('/groups.pdf', [GroupReportController::class, 'pdf'])->name('groups.pdf');
    Route::get('/groups.xlsx', [GroupReportController::class, 'excel'])->name('groups.excel');
    Route::get('/customer-balances.pdf', [CustomerBalancesReportController::class, 'pdf'])->name('customer-balances.pdf');
    Route::get('/customer-balances.xlsx', [CustomerBalancesReportController::class, 'excel'])->name('customer-balances.excel');
    Route::get('/general-ledger.pdf', [GeneralLedgerReportController::class, 'pdf'])->name('general-ledger.pdf');
    Route::get('/general-ledger.xlsx', [GeneralLedgerReportController::class, 'excel'])->name('general-ledger.excel');
    Route::get('/income-statement.pdf', [IncomeStatementReportController::class, 'pdf'])->name('income-statement.pdf');
    Route::get('/income-statement.xlsx', [IncomeStatementReportController::class, 'excel'])->name('income-statement.excel');
    Route::get('/balance-sheet.pdf', [BalanceSheetReportController::class, 'pdf'])->name('balance-sheet.pdf');
    Route::get('/balance-sheet.xlsx', [BalanceSheetReportController::class, 'excel'])->name('balance-sheet.excel');
});
