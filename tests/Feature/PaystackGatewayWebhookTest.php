<?php

/**
 * The gateway routes (webhooks/paystack/gateway and
 * webhooks/paystack/license/gateway) are reached only via oguapaymentwebhook,
 * the shared URL Paystack calls for every Ogua project. Each must reject
 * anything without a valid gateway signature — since every project on the
 * account shares the same Paystack secret key, a real Paystack signature
 * alone isn't proof a request came via the gateway rather than being
 * replayed from another project's traffic — and, once verified, must feed
 * the same controller the direct route uses.
 */

use App\Enums\LicenseSaleStatus;
use App\Enums\PaymentIntentStatus;
use App\Models\Branch;
use App\Models\DesktopLicenseSale;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

/**
 * A PaymentIntent whose account is actually assigned to its own initiating
 * agent — the bare factory pairs an independently-random account and agent,
 * which RecordCollectionAction::assertRecordable() correctly refuses to
 * complete a collection for ("You are not assigned to this account.").
 */
function makeAssignedPaymentIntent(array $attributes): PaymentIntent
{
    $branch = Branch::factory()->create();
    $agent = User::factory()->fieldAgent($branch)->create();
    $account = SavingsAccount::factory()->create([
        'branch_id' => $branch->id,
        'company_id' => $branch->company_id,
        'agent_id' => $agent->id,
    ]);

    return PaymentIntent::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'company_id' => $branch->company_id,
        'payable_id' => $account->id,
        'initiated_by' => $agent->id,
    ], $attributes));
}

function postToSusuGateway(string $uri, string $body, ?string $paystackSignature, ?string $gatewaySignature)
{
    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($paystackSignature !== null) {
        $headers['HTTP_X_PAYSTACK_SIGNATURE'] = $paystackSignature;
    }

    if ($gatewaySignature !== null) {
        $headers['HTTP_X_GATEWAY_SIGNATURE'] = $gatewaySignature;
    }

    return test()->call('POST', $uri, [], [], [], $headers, $body);
}

beforeEach(function (): void {
    seedRoles();

    config([
        'services.paystack.secret_key' => 'test_secret_key',
        'services.webhook_gateway.forward_secret' => 'test-forward-secret',
    ]);
});

it('rejects a susu-payment forward with no gateway signature', function (): void {
    $intent = makeAssignedPaymentIntent([
        'status' => PaymentIntentStatus::PayOffline,
        'provider_reference' => 'SUSU-gateway-1',
    ]);

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'SUSU-gateway-1', 'status' => 'success']]);
    $paystackSignature = hash_hmac('sha512', $body, 'test_secret_key');

    postToSusuGateway('/api/webhooks/paystack/gateway', $body, $paystackSignature, null)->assertStatus(401);

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::PayOffline);
});

it('accepts a correctly forwarded susu-payment webhook and completes it the same as the direct route', function (): void {
    $intent = makeAssignedPaymentIntent([
        'status' => PaymentIntentStatus::PayOffline,
        'provider_reference' => 'SUSU-gateway-2',
    ]);

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'SUSU-gateway-2', 'status' => 'success']]);
    $paystackSignature = hash_hmac('sha512', $body, 'test_secret_key');
    $gatewaySignature = hash_hmac('sha256', $body, 'test-forward-secret');

    postToSusuGateway('/api/webhooks/paystack/gateway', $body, $paystackSignature, $gatewaySignature)->assertOk();

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Success);
});

it('rejects a license forward with no gateway signature', function (): void {
    $sale = DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Pending,
        'provider_reference' => 'SUSULIC-gateway-1',
    ]);

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'SUSULIC-gateway-1', 'status' => 'success']]);
    $paystackSignature = hash_hmac('sha512', $body, 'test_secret_key');

    postToSusuGateway('/api/webhooks/paystack/license/gateway', $body, $paystackSignature, null)->assertStatus(401);

    expect($sale->fresh()->status)->toBe(LicenseSaleStatus::Pending);
});

it('accepts a correctly forwarded license webhook and issues the key the same as the direct route', function (): void {
    Config::set('license.private_key_path', __DIR__.'/../Fixtures/license/test_private_key.pem');
    Mail::fake();

    $sale = DesktopLicenseSale::factory()->create([
        'status' => LicenseSaleStatus::Pending,
        'provider_reference' => 'SUSULIC-gateway-2',
    ]);

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'SUSULIC-gateway-2', 'status' => 'success']]);
    $paystackSignature = hash_hmac('sha512', $body, 'test_secret_key');
    $gatewaySignature = hash_hmac('sha256', $body, 'test-forward-secret');

    postToSusuGateway('/api/webhooks/paystack/license/gateway', $body, $paystackSignature, $gatewaySignature)->assertOk();

    expect($sale->fresh()->status)->toBe(LicenseSaleStatus::Issued);
});

it('rejects a forward whose gateway signature was made with the wrong secret', function (): void {
    $intent = makeAssignedPaymentIntent([
        'status' => PaymentIntentStatus::PayOffline,
        'provider_reference' => 'SUSU-gateway-3',
    ]);

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'SUSU-gateway-3', 'status' => 'success']]);
    $paystackSignature = hash_hmac('sha512', $body, 'test_secret_key');
    $gatewaySignature = hash_hmac('sha256', $body, 'some-other-secret');

    postToSusuGateway('/api/webhooks/paystack/gateway', $body, $paystackSignature, $gatewaySignature)->assertStatus(401);

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::PayOffline);
});

it('rejects every forward while no gateway secret is configured', function (): void {
    config(['services.webhook_gateway.forward_secret' => null]);

    $intent = makeAssignedPaymentIntent([
        'status' => PaymentIntentStatus::PayOffline,
        'provider_reference' => 'SUSU-gateway-4',
    ]);

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'SUSU-gateway-4', 'status' => 'success']]);
    $paystackSignature = hash_hmac('sha512', $body, 'test_secret_key');
    $gatewaySignature = hash_hmac('sha256', $body, '');

    postToSusuGateway('/api/webhooks/paystack/gateway', $body, $paystackSignature, $gatewaySignature)->assertStatus(401);

    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::PayOffline);
});
