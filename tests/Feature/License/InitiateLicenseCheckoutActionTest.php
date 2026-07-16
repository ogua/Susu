<?php

use App\Actions\License\InitiateLicenseCheckoutAction;
use App\Enums\LicenseSaleStatus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.paystack.base_url' => 'https://api.paystack.co']);
});

it('creates a pending sale and returns the Paystack authorization url', function (): void {
    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123', 'reference' => 'lic-ref-1'],
        ]),
    ]);

    $result = app(InitiateLicenseCheckoutAction::class)->execute(
        'install-abc',
        'Kwame Asante',
        'kwame@example.com',
        '+233244000000',
        'https://susuapp.test/license/callback',
    );

    expect($result['sale']->status)->toBe(LicenseSaleStatus::Pending)
        ->and($result['sale']->install_id)->toBe('install-abc')
        ->and($result['sale']->amount)->toBe(config('license.price'))
        ->and($result['authorization_url'])->toBe('https://checkout.paystack.com/abc123');
});

it('marks the sale failed when Paystack returns no authorization url', function (): void {
    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response(['status' => false, 'data' => []]),
    ]);

    $result = app(InitiateLicenseCheckoutAction::class)->execute(
        'install-xyz',
        'Ama Serwaa',
        'ama@example.com',
        null,
        'https://susuapp.test/license/callback',
    );

    expect($result['authorization_url'])->toBeNull()
        ->and($result['sale']->status)->toBe(LicenseSaleStatus::Failed);
});
