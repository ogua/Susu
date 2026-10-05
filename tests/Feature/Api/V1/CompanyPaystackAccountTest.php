<?php

use App\Enums\PaymentIntentStatus;
use App\Models\Branch;
use App\Models\CompanyPaymentSetting;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    seedRoles();

    config(['services.paystack.secret_key' => 'sk_platform']);
    config(['services.paystack.base_url' => 'https://api.paystack.co']);

    $this->branch = Branch::factory()->create();
    $this->company = $this->branch->company;
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $product = SavingsProduct::factory()->firstContributionCommission()->create([
        'company_id' => $this->company->id,
        'contribution_amount' => 500,
    ]);

    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->company->id,
        'customer_id' => Customer::factory()->forBranch($this->branch)->create()->id,
        'savings_product_id' => $product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);
});

function chargeAccount(): PaymentIntent
{
    $response = test()->actingAs(test()->agent, 'sanctum')->postJson('/api/v1/payments/charge', [
        'savings_account_id' => test()->account->id,
        'amount' => 500,
        'phone' => '0244000111',
        'provider' => 'mtn',
    ])->assertCreated();

    return PaymentIntent::find($response->json('intent.id'));
}

function postCompanyWebhook(string $uri, string $body, string $secret)
{
    return test()->call('POST', $uri, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, $secret),
    ], $body);
}

it('charges and verifies with the company key once the company connects Paystack', function (): void {
    CompanyPaymentSetting::factory()->create(['company_id' => $this->company->id, 'paystack_secret_key' => 'sk_company']);

    Http::fake([
        'https://api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'pay_offline']]),
        'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success']]),
    ]);

    $intent = chargeAccount();
    expect($intent->paystack_account)->toBe('company');

    $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/payments/{$intent->id}/verify")
        ->assertOk()->assertJsonPath('intent.status', 'success');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk_company'));
    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk_platform'));
});

it('keeps using the platform key for companies that have not connected Paystack', function (): void {
    Http::fake(['https://api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'pay_offline']])]);

    expect(chargeAccount()->paystack_account)->toBe('platform');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk_platform'));
});

it('refuses mobile money when no Paystack key is configured anywhere', function (): void {
    config(['services.paystack.secret_key' => '']);
    Http::fake();

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/payments/charge', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
        'phone' => '0244000111',
        'provider' => 'mtn',
    ])->assertUnprocessable();

    Http::assertNothingSent();
    expect(PaymentIntent::count())->toBe(0);
});

it('settles a company charge from the company webhook signed with the company key', function (): void {
    CompanyPaymentSetting::factory()->create(['company_id' => $this->company->id, 'paystack_secret_key' => 'sk_company']);
    Http::fake(['https://api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'pay_offline']])]);
    $intent = chargeAccount();

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $intent->provider_reference, 'status' => 'success']]);
    $uri = "/api/webhooks/paystack/companies/{$this->company->id}";

    postCompanyWebhook($uri, $body, 'sk_platform')->assertUnauthorized();
    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::PayOffline);

    postCompanyWebhook($uri, $body, 'sk_company')->assertOk();
    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Success);
});

it('never lets a platform webhook settle a company-account charge', function (): void {
    CompanyPaymentSetting::factory()->create(['company_id' => $this->company->id, 'paystack_secret_key' => 'sk_company']);
    Http::fake(['https://api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'pay_offline']])]);
    $intent = chargeAccount();

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $intent->provider_reference, 'status' => 'success']]);
    postCompanyWebhook('/api/webhooks/paystack', $body, 'sk_platform')->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::PayOffline);
});

it('never lets one company webhook settle another company charge', function (): void {
    Http::fake(['https://api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'pay_offline']])]);
    CompanyPaymentSetting::factory()->create(['company_id' => $this->company->id, 'paystack_secret_key' => 'sk_company']);
    $intent = chargeAccount();

    $other = CompanyPaymentSetting::factory()->create(['paystack_secret_key' => 'sk_other']);
    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $intent->provider_reference, 'status' => 'success']]);

    postCompanyWebhook("/api/webhooks/paystack/companies/{$other->company_id}", $body, 'sk_other')->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::PayOffline);
});

it('404s the company webhook for a company without its own Paystack account', function (): void {
    postCompanyWebhook("/api/webhooks/paystack/companies/{$this->company->id}", '{}', 'sk_platform')->assertNotFound();
});
