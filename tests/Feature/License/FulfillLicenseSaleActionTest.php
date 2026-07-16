<?php

use App\Actions\License\FulfillLicenseSaleAction;
use App\Enums\LicenseSaleStatus;
use App\Mail\LicenseKeyIssuedMail;
use App\Models\DesktopLicenseSale;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    config(['services.paystack.base_url' => 'https://api.paystack.co']);
    Config::set('license.private_key_path', __DIR__.'/../../Fixtures/license/test_private_key.pem');
    Mail::fake();
});

it('verifies, issues a key, and emails + texts it on a successful payment', function (): void {
    $sale = DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Pending,
        'provider_reference' => 'lic-ref-ok',
        'customer_phone' => '+233244000000',
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success'],
        ]),
    ]);

    $result = app(FulfillLicenseSaleAction::class)->execute($sale);

    expect($result->status)->toBe(LicenseSaleStatus::Issued)
        ->and($result->license_key)->not->toBeNull()
        ->and($result->notified_at)->not->toBeNull();

    Mail::assertSent(LicenseKeyIssuedMail::class, fn ($mail) => $mail->sale->id === $sale->id);
});

it('marks the sale failed on a failed payment and sends no notification', function (): void {
    $sale = DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Pending,
        'provider_reference' => 'lic-ref-fail',
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'failed'],
        ]),
    ]);

    $result = app(FulfillLicenseSaleAction::class)->execute($sale);

    expect($result->status)->toBe(LicenseSaleStatus::Failed)
        ->and($result->license_key)->toBeNull();

    Mail::assertNotSent(LicenseKeyIssuedMail::class);
});

it('is idempotent and only ever sends one notification when called twice (webhook + callback race)', function (): void {
    $sale = DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Pending,
        'provider_reference' => 'lic-ref-race',
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success'],
        ]),
    ]);

    $first = app(FulfillLicenseSaleAction::class)->execute($sale);
    $second = app(FulfillLicenseSaleAction::class)->execute($first->fresh());

    expect($second->license_key)->toBe($first->license_key);
    Mail::assertSent(LicenseKeyIssuedMail::class, 1);
});

it('applies an already-known webhook outcome without calling Paystack again via complete()', function (): void {
    $sale = DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Pending,
        'provider_reference' => 'lic-ref-webhook',
    ]);

    Http::fake(); // no verify endpoint faked — complete() must not call it

    $result = app(FulfillLicenseSaleAction::class)->complete($sale, 'success', ['data' => ['status' => 'success']]);

    expect($result->status)->toBe(LicenseSaleStatus::Issued);
    Http::assertNothingSent();
});
