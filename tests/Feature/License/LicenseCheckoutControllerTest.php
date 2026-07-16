<?php

use App\Enums\LicenseSaleStatus;
use App\Models\DesktopLicenseSale;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    config(['services.paystack.base_url' => 'https://api.paystack.co']);
    Config::set('license.private_key_path', __DIR__.'/../../Fixtures/license/test_private_key.pem');
    Mail::fake();
});

it('shows the activation form with the install id prefilled', function (): void {
    $this->get('/license/activate/install-42')
        ->assertOk()
        ->assertSee('install-42', false);
});

it('redirects to the Paystack authorization url on checkout', function (): void {
    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz', 'reference' => 'ref-checkout'],
        ]),
    ]);

    $response = $this->post('/license/checkout', [
        'install_id' => 'install-1',
        'customer_name' => 'Yaw Boateng',
        'customer_email' => 'yaw@example.com',
        'customer_phone' => '+233201234567',
    ]);

    $response->assertRedirect('https://checkout.paystack.com/xyz');
    expect(DesktopLicenseSale::where('install_id', 'install-1')->exists())->toBeTrue();
});

it('rejects checkout without a valid email', function (): void {
    $this->post('/license/checkout', [
        'install_id' => 'install-1',
        'customer_name' => 'Yaw Boateng',
        'customer_email' => 'not-an-email',
    ])->assertSessionHasErrors('customer_email');
});

it('shows the issued key on a successful callback', function (): void {
    $sale = DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Pending,
        'provider_reference' => 'ref-callback-ok',
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success'],
        ]),
    ]);

    $this->get('/license/callback?reference=ref-callback-ok')
        ->assertOk()
        ->assertSee('Payment successful');

    expect($sale->fresh()->status)->toBe(LicenseSaleStatus::Issued);
});

it('shows a not-found message for an unknown reference', function (): void {
    $this->get('/license/callback?reference=does-not-exist')
        ->assertOk()
        ->assertSee("couldn't find that payment", false);
});
