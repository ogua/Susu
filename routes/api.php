<?php

use App\Http\Controllers\Api\Ussd\UssdConnectorController;
use App\Http\Controllers\Api\Webhooks\PaystackWebhookController;
use App\Http\Controllers\License\LicenseWebhookController;
use App\Http\Middleware\VerifyUssdSignature;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('routes/api/v1.php'));

// Outside v1/auth: Paystack calls this directly, authenticated only by
// signature (VerifyPaystackSignature), never a Sanctum token.
Route::post('/webhooks/paystack', PaystackWebhookController::class)
    ->middleware('paystack.signature')
    ->name('webhooks.paystack');

// Separate from the susu-payment webhook above so a license sale's
// fulfillment path never touches that money-movement code (see plan Phase 6).
Route::post('/webhooks/paystack/license', LicenseWebhookController::class)
    ->middleware('paystack.signature')
    ->name('webhooks.paystack.license');

// Reached via oguapaymentwebhook — the shared gateway holding the one
// webhook URL Paystack allows across all Ogua projects — once Paystack's
// dashboard is pointed at it instead of the direct routes above. Feed the
// same controllers; paystack.signature still verifies the (relayed,
// unchanged) original Paystack signature, and verify.gateway.signature
// additionally requires the forward to have actually come from the gateway.
Route::post('/webhooks/paystack/gateway', PaystackWebhookController::class)
    ->middleware(['verify.gateway.signature', 'paystack.signature'])
    ->name('webhooks.paystack.gateway');

// A company that connected its own Paystack account points that account's
// webhook here; paystack.signature verifies against that company's key.
Route::post('/webhooks/paystack/companies/{company}', PaystackWebhookController::class)
    ->middleware('paystack.signature')
    ->whereUuid('company')
    ->name('webhooks.paystack.company');

// Called only by the central Ogua USSD platform, signed with a shared secret
// (VerifyUssdSignature). Identifies customers by phone and answers USSD menu
// actions; withdrawals go through RequestWithdrawalAction like the app's.
Route::prefix('ussd')
    ->middleware(['throttle:600,1', VerifyUssdSignature::class])
    ->name('api.ussd.')
    ->group(function (): void {
        Route::post('/identify', [UssdConnectorController::class, 'identify'])->name('identify');
        Route::post('/actions/{key}', [UssdConnectorController::class, 'action'])->name('action');
    });

Route::post('/webhooks/paystack/license/gateway', LicenseWebhookController::class)
    ->middleware(['verify.gateway.signature', 'paystack.signature'])
    ->name('webhooks.paystack.license.gateway');
