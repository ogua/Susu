<?php

use App\Http\Controllers\Api\Webhooks\PaystackWebhookController;
use App\Http\Controllers\License\LicenseWebhookController;
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
