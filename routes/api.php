<?php

use App\Http\Controllers\Api\Webhooks\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('routes/api/v1.php'));

// Outside v1/auth: Paystack calls this directly, authenticated only by
// signature (VerifyPaystackSignature), never a Sanctum token.
Route::post('/webhooks/paystack', PaystackWebhookController::class)
    ->middleware('paystack.signature')
    ->name('webhooks.paystack');
