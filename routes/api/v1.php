<?php

use App\Http\Controllers\Api\V1\Agent;
use App\Http\Controllers\Api\V1\AgentPositionController;
use App\Http\Controllers\Api\V1\AgentRouteController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AppUpdateController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\ProfilePhotoController;
use App\Http\Controllers\Api\V1\CollectionSheetController;
use App\Http\Controllers\Api\V1\CompanyIntegrationController;
use App\Http\Controllers\Api\V1\CompanySubscriptionController;
use App\Http\Controllers\Api\V1\Customer;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\GroupLoanController;
use App\Http\Controllers\Api\V1\LedgerController;
use App\Http\Controllers\Api\V1\LoanController;
use App\Http\Controllers\Api\V1\LoanGroupController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RecordClientDevice;
use App\Http\Middleware\RequireApiPasswordChange;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('auth.login');
Route::post('/auth/login/two-factor', [AuthController::class, 'twoFactor'])
    ->middleware('throttle:10,1')
    ->name('auth.login.two-factor');
Route::post('/auth/login/two-factor/resend', [AuthController::class, 'resendTwoFactor'])
    ->middleware('throttle:5,1')
    ->name('auth.login.two-factor.resend');

// Public: a signed-out or suspended device must still learn it has to update.
Route::get('/app/update-check', AppUpdateController::class)
    ->middleware('throttle:60,1')
    ->name('app.update-check');

Route::middleware(['auth:sanctum', EnsureAccountIsActive::class, RecordClientDevice::class, RequireApiPasswordChange::class])->group(function (): void {
    Route::put('/auth/password', [PasswordController::class, 'update'])
        ->middleware('throttle:10,1')->name('auth.password.update');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('/auth/me/photo', [ProfilePhotoController::class, 'update'])
        ->middleware('throttle:10,1')->name('auth.me.photo.update');
    Route::delete('/auth/me/photo', [ProfilePhotoController::class, 'destroy'])->name('auth.me.photo.destroy');
    Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');

    // Offline sync (agents now; desktop back-office roles reuse the same protocol)
    Route::middleware(['role:field_agent|branch_manager|company_admin', 'throttle:30,1'])->group(function (): void {
        Route::post('/sync/batch', [SyncController::class, 'batch'])->name('sync.batch');
        Route::get('/sync/bootstrap', [SyncController::class, 'bootstrap'])->name('sync.bootstrap');
        Route::get('/sync/delta', [SyncController::class, 'delta'])->name('sync.delta');
    });

    Route::middleware('role:company_admin')->group(function (): void {
        Route::get('/company/integrations', [CompanyIntegrationController::class, 'show'])->name('company.integrations.show');
        Route::put('/company/integrations', [CompanyIntegrationController::class, 'update'])->name('company.integrations.update');
        Route::get('/company/subscription', [CompanySubscriptionController::class, 'show'])->name('company.subscription.show');
        Route::get('/company/subscription/plans', [CompanySubscriptionController::class, 'plans'])->name('company.subscription.plans');
        Route::post('/company/subscription/plan', [CompanySubscriptionController::class, 'changePlan'])
            ->middleware('throttle:10,1')->name('company.subscription.plan.change');
        Route::post('/company/subscription/invoices/{invoice}/checkout', [CompanySubscriptionController::class, 'checkout'])
            ->middleware('throttle:10,1')->name('company.subscription.invoices.checkout');
        Route::post('/company/subscription/invoices/{invoice}/verify', [CompanySubscriptionController::class, 'verify'])
            ->middleware('throttle:20,1')->name('company.subscription.invoices.verify');
        Route::get('/company/subscription/invoices/{invoice}/pdf-url', [CompanySubscriptionController::class, 'pdfUrl'])
            ->name('company.subscription.invoices.pdf-url');
    });

    Route::prefix('agent')->name('agent.')->middleware('role:field_agent|branch_manager|company_admin')->group(function (): void {
        Route::get('/accounts', [Agent\AccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{account}/statement', [Agent\AccountController::class, 'statement'])->name('accounts.statement');
        Route::get('/accounts/{account}/statement-url', [Agent\AccountController::class, 'statementUrl'])->name('accounts.statement-url');
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
        Route::get('/accounts/{account}/statement', [Customer\AccountController::class, 'statement'])->name('accounts.statement');
        Route::get('/accounts/{account}/statement-url', [Customer\AccountController::class, 'statementUrl'])->name('accounts.statement-url');
        Route::get('/withdrawal-requests', [Customer\WithdrawalRequestController::class, 'index'])->name('withdrawals.index');
        Route::post('/withdrawal-requests', [Customer\WithdrawalRequestController::class, 'store'])->name('withdrawals.store');
    });

    // Back-office customer list, branch transfer (single + bulk) and agent assignment.
    Route::prefix('customers')->name('customers.')
        ->middleware(['throttle:30,1', 'role:branch_manager|company_admin'])
        ->group(function (): void {
            Route::get('/', [CustomerController::class, 'index'])->name('index');
            Route::get('/overview', [CustomerController::class, 'overview'])->name('overview');
            Route::post('/transfer', [CustomerController::class, 'bulkTransfer'])->name('transfer.bulk');
            Route::post('/{customer}/transfer', [CustomerController::class, 'transfer'])->name('transfer');
            Route::post('/{customer}/assign-agent', [CustomerController::class, 'assignAgent'])->name('assign-agent');
            Route::patch('/{customer}', [CustomerController::class, 'update'])->name('update');
        });

    // Shared by agent (collect screen) and customer (deposit screen) roles —
    // RecordCollectionAction enforces who may act on a given account.
    Route::prefix('payments')->name('payments.')->middleware('throttle:20,1')->group(function (): void {
        Route::post('/charge', [PaymentController::class, 'chargeMobileMoney'])->name('charge');
        Route::post('/initialize', [PaymentController::class, 'initializeCheckout'])->name('initialize');
        Route::post('/{intent}/submit-otp', [PaymentController::class, 'submitOtp'])->name('submit-otp');
        Route::post('/{intent}/verify', [PaymentController::class, 'verify'])->name('verify');
        Route::get('/{intent}', [PaymentController::class, 'show'])->name('show');

        // Back-office only (mirrors PaymentIntentPolicy::viewAny) — the desktop
        // hybrid-mode payments view, not agents/customers.
        Route::middleware('role:branch_manager|company_admin')->group(function (): void {
            Route::get('/', [PaymentController::class, 'index'])->name('index');
        });
    });

    // Shared by agent (apply/repay on a customer's behalf) and customer
    // (self-service apply) roles — ApplyForLoanAction/RecordLoanRepaymentAction
    // enforce who may act on a given loan.
    Route::prefix('loans')->name('loans.')->middleware('throttle:20,1')->group(function (): void {
        Route::get('/products', [LoanController::class, 'products'])->name('products');
        Route::get('/eligibility', [LoanController::class, 'eligibility'])->name('eligibility');
        Route::post('/calculator', [LoanController::class, 'calculate'])->name('calculator');
        Route::get('/', [LoanController::class, 'index'])->name('index');
        Route::post('/', [LoanController::class, 'store'])->name('store');
        Route::get('/{loan}', [LoanController::class, 'show'])->name('show');

        // Repayments are staff-recorded only (cash collected in the field).
        Route::post('/{loan}/repayments', [LoanController::class, 'recordRepayment'])
            ->middleware('role:field_agent|branch_manager|company_admin')
            ->name('repayments.store');

        // Re-date unpaid installments; manager-only (RecalculateScheduleRequest).
        Route::post('/{loan}/recalculate-schedule', [LoanController::class, 'recalculateSchedule'])
            ->name('recalculate-schedule');
    });

    // Staff-only — a group loan is issued per member; roster management
    // (create group, add/remove members) lives here too.
    Route::prefix('loan-groups')->name('loan-groups.')
        ->middleware(['throttle:20,1', 'role:field_agent|branch_manager|company_admin'])
        ->group(function (): void {
            Route::get('/', [LoanGroupController::class, 'index'])->name('index');
            Route::get('/{loanGroup}', [LoanGroupController::class, 'show'])->name('show');
            Route::get('/{loanGroup}/history', [LoanGroupController::class, 'history'])->name('history');
            Route::post('/{loanGroup}/issue-loans', [LoanGroupController::class, 'issueLoans'])->name('issue-loans');
            Route::post('/{loanGroup}/open-savings', [LoanGroupController::class, 'openSavings'])->name('open-savings');
            Route::post('/', [LoanGroupController::class, 'store'])->name('store');
            Route::post('/{loanGroup}/members', [LoanGroupController::class, 'storeMember'])->name('members.store');
            Route::delete('/{loanGroup}/members/{member}', [LoanGroupController::class, 'destroyMember'])->name('members.destroy');
        });

    // "Enter Transaction": who is due on a date (by group and/or officer), and
    // posting the filled-in sheet in one all-or-nothing request.
    Route::prefix('collection-sheet')->name('collection-sheet.')
        ->middleware(['throttle:30,1', 'role:field_agent|branch_manager|company_admin'])
        ->group(function (): void {
            Route::get('/', [CollectionSheetController::class, 'show'])->name('show');
            Route::post('/', [CollectionSheetController::class, 'store'])->name('store');
        });

    Route::prefix('group-loans')->name('group-loans.')
        ->middleware(['throttle:20,1', 'role:field_agent|branch_manager|company_admin'])
        ->group(function (): void {
            Route::get('/', [GroupLoanController::class, 'index'])->name('index');
            Route::get('/{groupLoan}', [GroupLoanController::class, 'show'])->name('show');
            Route::post('/', [GroupLoanController::class, 'store'])->name('store');
            Route::post('/{groupLoan}/deposit', [GroupLoanController::class, 'recordDeposit'])->name('deposit.store');
            Route::post('/{groupLoan}/activate', [GroupLoanController::class, 'activate'])->name('activate');
            Route::post('/{groupLoan}/repayments', [GroupLoanController::class, 'recordRepayment'])->name('repayments.store');
            Route::post('/{groupLoan}/cancel', [GroupLoanController::class, 'cancel'])->name('cancel');
            Route::post('/{groupLoan}/recalculate-schedule', [GroupLoanController::class, 'recalculateSchedule'])->name('recalculate-schedule');

            // Write-off is manager-tier only (enforced in WriteOffGroupLoanRequest).
            Route::post('/{groupLoan}/write-off', [GroupLoanController::class, 'writeOff'])->name('write-off');
        });

    // Role-shaped dashboard stats — one endpoint per home screen.
    Route::prefix('dashboard')->name('dashboard.')->middleware('throttle:30,1')->group(function (): void {
        Route::get('/agent', [DashboardController::class, 'agent'])
            ->middleware('role:field_agent|branch_manager|company_admin')
            ->name('agent');
        Route::get('/branch', [DashboardController::class, 'branch'])
            ->middleware('role:branch_manager|company_admin')
            ->name('branch');
        Route::get('/customer', [DashboardController::class, 'customer'])
            ->middleware('role:customer')
            ->name('customer');
    });

    // Manager views of agent tracking (web Agent Tracking + Track agent pages).
    Route::get('/agents/positions', [AgentPositionController::class, 'index'])
        ->middleware(['role:branch_manager|company_admin', 'throttle:60,1'])
        ->name('agents.positions');
    Route::get('/agents/{agent}/route', [AgentRouteController::class, 'show'])
        ->middleware(['role:branch_manager|company_admin', 'throttle:60,1'])
        ->name('agents.route');

    // Back-office reports: JSON data plus short-lived signed download URLs
    // onto the web PDF/Excel routes.
    Route::prefix('reports')->name('reports.')->middleware(['role:branch_manager|company_admin', 'throttle:30,1'])->group(function (): void {
        Route::get('/{report}', [ReportController::class, 'show'])->name('show');
        Route::get('/{report}/download-url', [ReportController::class, 'downloadUrl'])->name('download-url');
    });

    // Chart of accounts + per-account journal entries (desktop hybrid views).
    Route::prefix('ledger')->name('ledger.')->middleware(['role:branch_manager|company_admin', 'throttle:30,1'])->group(function (): void {
        Route::get('/accounts', [LedgerController::class, 'accounts'])->name('accounts');
        Route::get('/accounts/{account}/entries', [LedgerController::class, 'entries'])->name('accounts.entries');
    });

    // Shared by agent (records contributions in the field) and customer
    // (views their own memberships) roles — payouts are staff-only.
    Route::prefix('groups')->name('groups.')->middleware('throttle:20,1')->group(function (): void {
        Route::get('/', [GroupController::class, 'index'])->name('index');
        Route::get('/{group}', [GroupController::class, 'show'])->name('show');

        Route::post('/{group}/contributions', [GroupController::class, 'storeContribution'])
            ->middleware('role:field_agent|branch_manager|company_admin')
            ->name('contributions.store');

        Route::post('/rounds/{round}/payout', [GroupController::class, 'payout'])
            ->middleware('role:branch_manager|company_admin')
            ->name('rounds.payout');
    });
});
