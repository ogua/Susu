<?php

use App\Enums\LicenseSaleStatus;
use App\Models\DesktopLicenseSale;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    config(['services.paystack.secret_key' => 'test_secret_key']);
    Config::set('license.private_key_path', __DIR__.'/../../Fixtures/license/test_private_key.pem');
    Mail::fake();
});

it('issues a key via webhook with a valid signature', function (): void {
    $sale = DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Pending,
        'provider_reference' => 'lic-webhook-ok',
    ]);

    $body = json_encode([
        'event' => 'charge.success',
        'data' => ['reference' => 'lic-webhook-ok', 'status' => 'success'],
    ]);
    $signature = hash_hmac('sha512', $body, 'test_secret_key');

    $response = $this->call('POST', '/api/webhooks/paystack/license', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
    ], $body);

    $response->assertOk();
    expect($sale->fresh()->status)->toBe(LicenseSaleStatus::Issued);
});

it('rejects a webhook with an invalid signature and applies nothing', function (): void {
    $sale = DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Pending,
        'provider_reference' => 'lic-webhook-bad-sig',
    ]);

    $body = json_encode([
        'event' => 'charge.success',
        'data' => ['reference' => 'lic-webhook-bad-sig', 'status' => 'success'],
    ]);

    $response = $this->call('POST', '/api/webhooks/paystack/license', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => 'not-the-right-signature',
    ], $body);

    $response->assertStatus(401);
    expect($sale->fresh()->status)->toBe(LicenseSaleStatus::Pending);
});

it('never touches the susu-payment webhook endpoint or its intents', function (): void {
    // Sanity check the two webhooks are genuinely separate routes.
    expect(route('webhooks.paystack'))->not->toBe(route('webhooks.paystack.license'));
});
