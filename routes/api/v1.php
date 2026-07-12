<?php

use App\Http\Controllers\Api\V1\Agent;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Customer;
use App\Http\Controllers\Api\V1\SyncController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

    // Offline sync (agents now; desktop back-office roles reuse the same protocol)
    Route::middleware(['role:field_agent|branch_manager|company_admin', 'throttle:30,1'])->group(function (): void {
        Route::post('/sync/batch', [SyncController::class, 'batch'])->name('sync.batch');
        Route::get('/sync/bootstrap', [SyncController::class, 'bootstrap'])->name('sync.bootstrap');
        Route::get('/sync/delta', [SyncController::class, 'delta'])->name('sync.delta');
    });

    Route::prefix('agent')->name('agent.')->middleware('role:field_agent|branch_manager|company_admin')->group(function (): void {
        Route::get('/accounts', [Agent\AccountController::class, 'index'])->name('accounts.index');
        Route::post('/customers', [Agent\CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}', [Agent\CustomerController::class, 'show'])->name('customers.show');
        Route::post('/collections', [Agent\CollectionController::class, 'store'])->name('collections.store');
        Route::get('/summary/today', [Agent\SummaryController::class, 'today'])->name('summary.today');
        Route::post('/summaries', [Agent\SummaryController::class, 'store'])->name('summaries.store');
        Route::post('/remittances', [Agent\RemittanceController::class, 'store'])->name('remittances.store');
        Route::post('/locations', [Agent\LocationController::class, 'store'])
            ->middleware('throttle:60,1')->name('locations.store');
        Route::post('/duty', [Agent\LocationController::class, 'duty'])->name('duty');
    });

    Route::prefix('customer')->name('customer.')->middleware('role:customer')->group(function (): void {
        Route::get('/accounts', [Customer\AccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{account}/transactions', [Customer\AccountController::class, 'transactions'])->name('accounts.transactions');
        Route::get('/withdrawal-requests', [Customer\WithdrawalRequestController::class, 'index'])->name('withdrawals.index');
        Route::post('/withdrawal-requests', [Customer\WithdrawalRequestController::class, 'store'])->name('withdrawals.store');
    });
});
