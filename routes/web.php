<?php

use App\Http\Controllers\Reports\CashPositionReportController;
use App\Http\Controllers\Reports\DefaultersReportController;
use App\Http\Controllers\Reports\TrialBalanceReportController;
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

Route::middleware('auth')->prefix('reports/{branch}')->name('reports.')->group(function (): void {
    Route::get('/trial-balance.pdf', [TrialBalanceReportController::class, 'pdf'])->name('trial-balance.pdf');
    Route::get('/trial-balance.xlsx', [TrialBalanceReportController::class, 'excel'])->name('trial-balance.excel');
    Route::get('/defaulters.pdf', [DefaultersReportController::class, 'pdf'])->name('defaulters.pdf');
    Route::get('/defaulters.xlsx', [DefaultersReportController::class, 'excel'])->name('defaulters.excel');
    Route::get('/cash-position.pdf', [CashPositionReportController::class, 'pdf'])->name('cash-position.pdf');
    Route::get('/cash-position.xlsx', [CashPositionReportController::class, 'excel'])->name('cash-position.excel');
});
