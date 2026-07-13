<?php

use App\Enums\PaymentIntentStatus;
use App\Models\AgentDailySummary;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    seedRoles();

    config(['services.paystack.secret_key' => 'test_secret_key']);
    config(['services.paystack.base_url' => 'https://api.paystack.co']);

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $this->product = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
    ]);

    $this->customer = Customer::factory()->forBranch($this->branch)->create();

    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);
});

it('initiates a charge, gets pay_offline, then verify posts the collection once confirmed', function (): void {
    Http::fake([
        'https://api.paystack.co/charge' => Http::response([
            'status' => true,
            'data' => ['status' => 'pay_offline', 'reference' => 'ref-1'],
        ]),
        'https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success', 'reference' => 'ref-1'],
        ]),
    ]);

    $charge = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/payments/charge', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
        'phone' => '0244000111',
        'provider' => 'mtn',
    ]);

    $charge->assertCreated()->assertJsonPath('intent.status', 'pay_offline');
    $intentId = $charge->json('intent.id');

    $verify = $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/payments/{$intentId}/verify");

    $verify->assertOk()->assertJsonPath('intent.status', 'success');
    expect($this->account->refresh()->balance)->toBe(0) // 500 collected, 500 day-1 commission
        ->and($this->account->contributions_this_cycle)->toBe(1);
});

it('debits the mobile money clearing account, not the agent cash account', function (): void {
    Http::fake([
        'https://api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'success', 'reference' => 'ref-2']]),
    ]);

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/payments/charge', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
        'phone' => '0244000111',
        'provider' => 'mtn',
    ])->assertCreated();

    $agentCash = app(ChartOfAccounts::class)->agentCash($this->agent->fresh());
    $momoClearing = app(ChartOfAccounts::class)->momoClearing($this->branch->company);

    expect($agentCash->refresh()->balance)->toBe(0)
        ->and($momoClearing->refresh()->balance)->toBe(500);
});

it('walks the send_otp path before completing', function (): void {
    Http::fake([
        'https://api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'send_otp', 'reference' => 'ref-3']]),
        'https://api.paystack.co/charge/submit_otp' => Http::response(['status' => true, 'data' => ['status' => 'success', 'reference' => 'ref-3']]),
    ]);

    $charge = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/payments/charge', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
        'phone' => '0244000111',
        'provider' => 'vod',
    ])->assertCreated()->assertJsonPath('intent.status', 'send_otp');

    $intentId = $charge->json('intent.id');

    $otp = $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/payments/{$intentId}/submit-otp", ['otp' => '123456']);

    $otp->assertOk()->assertJsonPath('intent.status', 'success');
    expect($this->account->refresh()->balance)->toBe(0);
});

it('never posts a ledger entry for a failed charge', function (): void {
    Http::fake([
        'https://api.paystack.co/charge' => Http::response(['status' => false, 'data' => ['status' => 'failed', 'reference' => 'ref-4']]),
    ]);

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/payments/charge', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
        'phone' => '0244000111',
        'provider' => 'mtn',
    ])->assertCreated()->assertJsonPath('intent.status', 'failed');

    expect($this->account->refresh()->balance)->toBe(0)
        ->and($this->account->contributions_this_cycle)->toBe(0);
});

it('queues exactly one customer notification for a momo collection', function (): void {
    Notification::fake();

    Http::fake([
        'https://api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'success', 'reference' => 'ref-5']]),
    ]);

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/payments/charge', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
        'phone' => '0244000111',
        'provider' => 'mtn',
    ])->assertCreated();

    expect(NotificationLog::where('customer_id', $this->customer->id)->count())->toBe(1);
});

it('lets a customer self-initiate and complete a deposit on their own account', function (): void {
    $customerUser = User::factory()->customerUser()->create(['company_id' => $this->branch->company_id]);
    $this->customer->update(['user_id' => $customerUser->id]);

    Http::fake([
        'https://api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'success', 'reference' => 'ref-6']]),
    ]);

    $this->actingAs($customerUser, 'sanctum')->postJson('/api/v1/payments/charge', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
        'phone' => '0244000111',
        'provider' => 'mtn',
    ])->assertCreated()->assertJsonPath('intent.status', 'success');

    // Self-service deposits have no agent day sheet.
    expect(AgentDailySummary::count())->toBe(0)
        ->and($this->account->refresh()->balance)->toBe(0);
});

it('completes via webhook with a valid signature and matches by client_reference', function (): void {
    $intent = PaymentIntent::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'payable_id' => $this->account->id,
        'payable_type' => SavingsAccount::class,
        'initiated_by' => $this->agent->id,
        'amount' => 500,
        'status' => PaymentIntentStatus::PayOffline,
        'provider_reference' => null,
    ]);

    $body = json_encode([
        'event' => 'charge.success',
        'data' => ['reference' => $intent->client_reference, 'status' => 'success'],
    ]);
    $signature = hash_hmac('sha512', $body, 'test_secret_key');

    $response = $this->call('POST', '/api/webhooks/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
    ], $body);

    $response->assertOk();
    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Success)
        ->and($intent->journal_entry_id)->not->toBeNull()
        ->and($this->account->refresh()->balance)->toBe(0);
});

it('rejects a webhook with an invalid signature and applies nothing', function (): void {
    $intent = PaymentIntent::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'payable_id' => $this->account->id,
        'payable_type' => SavingsAccount::class,
        'initiated_by' => $this->agent->id,
        'amount' => 500,
        'status' => PaymentIntentStatus::PayOffline,
    ]);

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $intent->client_reference, 'status' => 'success']]);

    $response = $this->call('POST', '/api/webhooks/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => 'not-the-right-signature',
    ], $body);

    $response->assertStatus(401);
    expect($intent->refresh()->status)->toBe(PaymentIntentStatus::PayOffline);
});

it('lets the webhook and a manual verify race without double-posting', function (): void {
    $intent = PaymentIntent::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'payable_id' => $this->account->id,
        'payable_type' => SavingsAccount::class,
        'initiated_by' => $this->agent->id,
        'amount' => 500,
        'status' => PaymentIntentStatus::PayOffline,
    ]);

    // Webhook wins first.
    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $intent->client_reference, 'status' => 'success']]);
    $signature = hash_hmac('sha512', $body, 'test_secret_key');
    $this->call('POST', '/api/webhooks/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
    ], $body)->assertOk();

    $firstEntryId = $intent->refresh()->journal_entry_id;
    expect($firstEntryId)->not->toBeNull();

    // The client's own poll arrives after — Paystack's verify would say success too.
    Http::fake([
        'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'reference' => $intent->client_reference]]),
    ]);

    $verify = $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/payments/{$intent->id}/verify");

    $verify->assertOk()->assertJsonPath('intent.journal_entry_id', $firstEntryId);
    expect($this->account->refresh()->balance)->toBe(0) // still only posted once
        ->and($this->account->contributions_this_cycle)->toBe(1);
});

it('lists payment intents scoped to the caller company, for back-office roles only', function (): void {
    $manager = User::factory()->branchManager($this->branch)->create();

    $ownIntent = PaymentIntent::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'payable_id' => $this->account->id,
        'payable_type' => SavingsAccount::class,
        'initiated_by' => $this->agent->id,
    ]);
    $otherIntent = PaymentIntent::factory()->create(); // different company entirely

    $response = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/payments');

    $response->assertOk();
    $ids = collect($response->json('intents'))->pluck('id');
    expect($ids)->toContain($ownIntent->id)
        ->and($ids)->not->toContain($otherIntent->id);

    $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/payments')->assertForbidden();
});
