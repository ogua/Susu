<?php

use App\Actions\Customers\ProvisionCustomerLoginAction;
use App\Actions\Payments\InitiateMobileMoneyChargeAction;
use App\Actions\Payments\VerifyPaymentIntentAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\LoanStatus;
use App\Enums\MobileMoneyProvider;
use App\Enums\PaymentIntentStatus;
use App\Enums\WithdrawalStatus;
use App\Jobs\Ussd\InitiateUssdContributionCharge;
use App\Jobs\Ussd\ReportUssdTransactionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Models\UssdPaymentReport;
use App\Models\WithdrawalRequest;
use App\Services\Ussd\UssdServiceUser;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

const USSD_SECRET = 'ussd-test-secret';

/**
 * POST to the USSD connector signed exactly as the platform's HttpProductConnector signs.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers  Overrides for the signed headers.
 */
function ussdPost(string $uri, array $body, array $headers = [], string $secret = USSD_SECRET): TestResponse
{
    $json = json_encode($body);
    $timestamp = $headers['X-Ussd-Timestamp'] ?? (string) time();
    $nonce = $headers['X-Ussd-Nonce'] ?? bin2hex(random_bytes(16));

    $headers = array_merge([
        'X-Ussd-Client' => 'oguaussd',
        'X-Ussd-Timestamp' => $timestamp,
        'X-Ussd-Nonce' => $nonce,
        'X-Ussd-Signature' => hash_hmac('sha256', "{$timestamp}.{$nonce}.{$json}", $secret),
    ], $headers);

    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', $uri, [], [], [], $server, $json);
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function susuAction(Customer $customer, array $payload = [], string $msisdn = '233244123456'): array
{
    return [
        'tenant_ref' => $customer->company_id,
        'subject_ref' => $customer->id,
        'msisdn' => $msisdn,
        'request_id' => hash('sha256', Str::random()),
        'payload' => (object) $payload,
    ];
}

beforeEach(function (): void {
    seedRoles();
    config(['services.ussd.client_id' => 'oguaussd', 'services.ussd.secret' => USSD_SECRET]);

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $this->product = SavingsProduct::factory()->firstContributionCommission()->create([
        'company_id' => $this->branch->company_id,
        'name' => 'Daily Susu',
        'contribution_amount' => 500,
    ]);

    $this->customer = Customer::factory()->forBranch($this->branch)->create([
        'first_name' => 'Kofi',
        'last_name' => 'Boateng',
        'phone' => '024 412 3456',
    ]);

    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);

    $collect = app(RecordCollectionAction::class);
    foreach (range(1, 4) as $day) {
        $collect->execute($this->agent, $this->account, 500);
    }
    $this->account->refresh();
});

it('rejects requests not signed by the platform', function (): void {
    ussdPost('/api/ussd/identify', ['msisdn' => '233244123456'], secret: 'wrong')->assertUnauthorized();
    ussdPost('/api/ussd/identify', ['msisdn' => '233244123456'], ['X-Ussd-Timestamp' => (string) (time() - 400)])->assertUnauthorized();

    ussdPost('/api/ussd/identify', ['msisdn' => '233244123456'], ['X-Ussd-Nonce' => 'once'])->assertOk();
    ussdPost('/api/ussd/identify', ['msisdn' => '233244123456'], ['X-Ussd-Nonce' => 'once'])->assertUnauthorized();
});

it('identifies a customer by phone whatever format it was stored in', function (): void {
    ussdPost('/api/ussd/identify', ['msisdn' => '233244123456'])
        ->assertOk()
        ->assertExactJson(['links' => [[
            'tenant_ref' => $this->branch->company_id,
            'tenant_name' => $this->branch->company->name,
            'subject_ref' => $this->customer->id,
            'label' => 'Kofi Boateng',
        ]]]);

    ussdPost('/api/ussd/identify', ['msisdn' => '233209999999'])->assertExactJson(['links' => []]);
});

it('shows savings balances', function (): void {
    ussdPost('/api/ussd/actions/susu.balance', susuAction($this->customer))
        ->assertOk()
        ->assertJsonPath('message', "Balances:\nDaily Susu: GHS ".number_format($this->account->balance / 100, 2));
});

it('lists recent transactions', function (): void {
    $message = ussdPost('/api/ussd/actions/susu.history', susuAction($this->customer))->json('message');

    expect($message)->toStartWith("Recent transactions:\n")
        ->and(substr_count($message, '+GHS 5.00'))->toBe(4);
});

it('shows outstanding loans', function (): void {
    Loan::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'loan_number' => 'LN-001',
        'status' => LoanStatus::Disbursed,
        'outstanding_balance' => 25_000,
    ]);

    ussdPost('/api/ussd/actions/susu.loan_balance', susuAction($this->customer))
        ->assertJsonPath('message', "Loans:\nLN-001: GHS 250.00 owed");
});

it('requests a withdrawal through the same action as the app, keyed by the platform transaction id', function (): void {
    ussdPost('/api/ussd/actions/susu.withdrawal', susuAction($this->customer))
        ->assertExactJson([
            'message' => 'Enter amount to withdraw (GHS)',
            'continue' => true,
            'state' => ['step' => 'amount', 'account_id' => $this->account->id],
        ]);

    ussdPost('/api/ussd/actions/susu.withdrawal', susuAction($this->customer, [
        'input' => '10',
        'state' => ['step' => 'amount', 'account_id' => $this->account->id],
    ]))->assertExactJson([
        'message' => "Withdraw GHS 10.00 from Daily Susu?\n1. Confirm\n2. Cancel",
        'continue' => true,
        'state' => ['step' => 'confirm', 'account_id' => $this->account->id, 'amount' => 1000],
        'transaction' => ['amount' => 1000, 'currency' => 'GHS'],
    ]);

    $transactionId = (string) Str::uuid();
    $confirm = susuAction($this->customer, [
        'input' => '1',
        'state' => ['step' => 'confirm', 'account_id' => $this->account->id, 'amount' => 1000],
        'transaction_id' => $transactionId,
    ]);

    ussdPost('/api/ussd/actions/susu.withdrawal', $confirm)
        ->assertJsonPath('message', 'Withdrawal request of GHS 10.00 submitted. Your agent will contact you to pay out.')
        ->assertJsonPath('transaction', ['status' => 'pending', 'reference' => $transactionId]);

    ussdPost('/api/ussd/actions/susu.withdrawal', $confirm)->assertJsonPath('transaction.status', 'pending');

    expect(WithdrawalRequest::query()->sole())
        ->id->toBe($transactionId)
        ->amount->toBe(1000)
        ->status->toBe(WithdrawalStatus::Pending)
        ->requested_by->toBeNull()
        ->customer_id->toBe($this->customer->id);
});

it('re-asks for an amount above the available balance', function (): void {
    ussdPost('/api/ussd/actions/susu.withdrawal', susuAction($this->customer, [
        'input' => '99999',
        'state' => ['step' => 'amount', 'account_id' => $this->account->id],
    ]))->assertJson([
        'message' => 'Available: GHS '.number_format($this->account->balance / 100, 2).".\nEnter amount to withdraw (GHS)",
        'continue' => true,
    ])->assertJsonMissingPath('transaction');
});

it('cancels a withdrawal without creating a request', function (): void {
    ussdPost('/api/ussd/actions/susu.withdrawal', susuAction($this->customer, [
        'input' => '2',
        'state' => ['step' => 'confirm', 'account_id' => $this->account->id, 'amount' => 1000],
        'transaction_id' => (string) Str::uuid(),
    ]))->assertExactJson(['message' => 'Withdrawal cancelled.', 'continue' => false]);

    expect(WithdrawalRequest::query()->count())->toBe(0);
});

it('reports a failed withdrawal when the balance changed before confirmation', function (): void {
    ussdPost('/api/ussd/actions/susu.withdrawal', susuAction($this->customer, [
        'input' => '1',
        'state' => ['step' => 'confirm', 'account_id' => $this->account->id, 'amount' => $this->account->balance + 100],
        'transaction_id' => (string) Str::uuid(),
    ]))->assertJson([
        'message' => 'Requested amount exceeds the available balance.',
        'transaction' => ['status' => 'failed'],
    ]);
});

it('never acts for a phone number that is not the customer', function (): void {
    ussdPost('/api/ussd/actions/susu.withdrawal', susuAction($this->customer, [
        'input' => '1',
        'state' => ['step' => 'confirm', 'account_id' => $this->account->id, 'amount' => 1000],
        'transaction_id' => (string) Str::uuid(),
    ], msisdn: '233209999999'))->assertJsonPath('message', 'We could not find your account. Please contact your Susu office.');

    expect(WithdrawalRequest::query()->count())->toBe(0);
});

it('never withdraws from another customer account smuggled into the state', function (): void {
    $other = Customer::factory()->forBranch($this->branch)->create();
    $otherAccount = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $other->id,
        'savings_product_id' => $this->product->id,
    ]);

    ussdPost('/api/ussd/actions/susu.withdrawal', susuAction($this->customer, [
        'input' => '1',
        'state' => ['step' => 'confirm', 'account_id' => $otherAccount->id, 'amount' => 100],
        'transaction_id' => (string) Str::uuid(),
    ]))->assertJsonPath('transaction.status', 'failed');

    expect(WithdrawalRequest::query()->count())->toBe(0);
});

describe('MoMo contributions', function (): void {
    beforeEach(function (): void {
        config(['services.paystack.secret_key' => 'sk_platform', 'services.paystack.base_url' => 'https://api.paystack.co']);
    });

    it('walks days, network and confirmation, then queues the charge to run after the session ends', function (): void {
        Queue::fake();

        ussdPost('/api/ussd/actions/susu.contribute', susuAction($this->customer))
            ->assertJsonPath('message', "How many days are you paying for?\nGHS 5.00 per day")
            ->assertJsonPath('state', ['step' => 'days', 'account_id' => $this->account->id]);

        ussdPost('/api/ussd/actions/susu.contribute', susuAction($this->customer, [
            'input' => '5',
            'state' => ['step' => 'days', 'account_id' => $this->account->id],
        ]))->assertJsonPath('message', "Pay with:\n1. MTN MoMo\n2. Telecel Cash\n3. AirtelTigo Money");

        ussdPost('/api/ussd/actions/susu.contribute', susuAction($this->customer, [
            'input' => '1',
            'state' => ['step' => 'network', 'account_id' => $this->account->id, 'amount' => 2500],
        ]))->assertExactJson([
            'message' => "Pay GHS 25.00 into Daily Susu with MTN MoMo?\n1. Confirm\n2. Cancel",
            'continue' => true,
            'state' => ['step' => 'confirm', 'account_id' => $this->account->id, 'amount' => 2500, 'provider' => 'mtn'],
            'transaction' => ['amount' => 2500, 'currency' => 'GHS'],
        ]);

        $transactionId = (string) Str::uuid();

        ussdPost('/api/ussd/actions/susu.contribute', susuAction($this->customer, [
            'input' => '1',
            'state' => ['step' => 'confirm', 'account_id' => $this->account->id, 'amount' => 2500, 'provider' => 'mtn'],
            'transaction_id' => $transactionId,
        ]))->assertExactJson([
            'message' => 'You will get a MoMo prompt for GHS 25.00 shortly. Enter your MoMo PIN to approve.',
            'continue' => false,
            'transaction' => ['status' => 'pending', 'reference' => $transactionId],
        ]);

        Queue::assertPushed(InitiateUssdContributionCharge::class, fn (InitiateUssdContributionCharge $job): bool => $job->clientReference === $transactionId
            && $job->amount === 2500
            && $job->phone === '0244123456'
            && $job->provider === 'mtn'
            && $job->savingsAccountId === $this->account->id
            && $job->delay !== null);
    });

    it('re-asks for days outside 1 to 31', function (string $days): void {
        ussdPost('/api/ussd/actions/susu.contribute', susuAction($this->customer, [
            'input' => $days,
            'state' => ['step' => 'days', 'account_id' => $this->account->id],
        ]))->assertJsonPath('message', "Enter 1 to 31 days.\nHow many days are you paying for?\nGHS 5.00 per day");
    })->with(['0', '32', 'abc']);

    it('fails the transaction when MoMo is not set up for the company', function (): void {
        Queue::fake();
        config(['services.paystack.secret_key' => '']);

        ussdPost('/api/ussd/actions/susu.contribute', susuAction($this->customer, [
            'input' => '1',
            'state' => ['step' => 'confirm', 'account_id' => $this->account->id, 'amount' => 2500, 'provider' => 'mtn'],
            'transaction_id' => (string) Str::uuid(),
        ]))->assertJson([
            'message' => 'MoMo payments are not set up for your Susu office yet.',
            'transaction' => ['status' => 'failed'],
        ]);

        Queue::assertNothingPushed();
    });

    it('charges a customer without a login as the inactive company USSD service user', function (): void {
        Http::fake(['https://api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'pay_offline']])]);
        $transactionId = (string) Str::uuid();

        (new InitiateUssdContributionCharge($this->customer->id, $this->account->id, 2500, '0244123456', 'mtn', $transactionId))
            ->handle(app(InitiateMobileMoneyChargeAction::class), app(UssdServiceUser::class));

        $intent = PaymentIntent::query()->where('client_reference', $transactionId)->sole();
        $serviceUser = User::query()->findOrFail($intent->initiated_by);

        expect($intent)
            ->amount->toBe(2500)
            ->phone->toBe('0244123456')
            ->channel->toBe(MobileMoneyProvider::Mtn)
            ->provider_reference->toBe('SUSU-'.$transactionId)
            ->and($serviceUser->hasRole('ussd_service'))->toBeTrue()
            ->and($serviceUser->company_id)->toBe($this->customer->company_id)
            ->and($serviceUser->hasActiveAccess())->toBeFalse();

        Http::assertSent(fn ($request): bool => $request['mobile_money'] === ['phone' => '0244123456', 'provider' => 'mtn']
            && $request['amount'] === '2500'
            && $request['reference'] === 'SUSU-'.$transactionId);
    });

    it('charges a customer with a login as themselves', function (): void {
        Http::fake(['https://api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'pay_offline']])]);
        $login = app(ProvisionCustomerLoginAction::class)->execute($this->customer, 'password');
        $transactionId = (string) Str::uuid();

        (new InitiateUssdContributionCharge($this->customer->id, $this->account->id, 2500, '0244123456', 'mtn', $transactionId))
            ->handle(app(InitiateMobileMoneyChargeAction::class), app(UssdServiceUser::class));

        expect(PaymentIntent::query()->where('client_reference', $transactionId)->sole()->initiated_by)->toBe($login->id);
    });

    it('reports the final MoMo outcome back to the platform once Paystack settles it', function (): void {
        Queue::fake([ReportUssdTransactionStatus::class]);
        Http::fake(['https://api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'pay_offline']])]);
        $transactionId = (string) Str::uuid();

        (new InitiateUssdContributionCharge($this->customer->id, $this->account->id, 2500, '0244123456', 'mtn', $transactionId))
            ->handle(app(InitiateMobileMoneyChargeAction::class), app(UssdServiceUser::class));

        expect(UssdPaymentReport::query()->find($transactionId))->not->toBeNull();
        Queue::assertNotPushed(ReportUssdTransactionStatus::class);

        $intent = PaymentIntent::query()->where('client_reference', $transactionId)->sole();
        app(VerifyPaymentIntentAction::class)->complete($intent, PaymentIntentStatus::Success, ['data' => ['status' => 'success']]);

        Queue::assertPushed(ReportUssdTransactionStatus::class, fn (ReportUssdTransactionStatus $job): bool => $job->clientReference === $transactionId);
    });

    it('does not report payments that did not start over USSD', function (): void {
        Queue::fake([ReportUssdTransactionStatus::class]);
        $intent = PaymentIntent::factory()->create(['client_reference' => (string) Str::uuid()]);

        $intent->update(['status' => PaymentIntentStatus::Failed]);

        Queue::assertNotPushed(ReportUssdTransactionStatus::class);
    });

    it('sends a signed status report to the platform and marks it reported', function (): void {
        config(['services.ussd.platform_url' => 'https://ussd.test']);
        Http::fake(['https://ussd.test/*' => Http::response(['status' => 'completed'])]);
        $transactionId = (string) Str::uuid();
        UssdPaymentReport::query()->create(['client_reference' => $transactionId]);
        PaymentIntent::factory()->create([
            'client_reference' => $transactionId,
            'provider_reference' => 'SUSU-'.$transactionId,
            'status' => PaymentIntentStatus::Success,
        ]);

        (new ReportUssdTransactionStatus($transactionId))->handle();

        Http::assertSent(function ($request) use ($transactionId): bool {
            $expected = hash_hmac('sha256', $request->header('X-Ussd-Timestamp')[0].'.'.$request->header('X-Ussd-Nonce')[0].'.'.$request->body(), USSD_SECRET);

            return $request->url() === "https://ussd.test/api/ussd/products/susu/transactions/{$transactionId}"
                && $request->header('X-Ussd-Signature')[0] === $expected
                && $request->data() === ['status' => 'completed', 'reference' => 'SUSU-'.$transactionId];
        });

        expect(UssdPaymentReport::query()->find($transactionId))
            ->reported_status->toBe('completed')
            ->reported_at->not->toBeNull();
    });

    it('leaves the report pending when the platform rejects it, so the retry can resend', function (): void {
        config(['services.ussd.platform_url' => 'https://ussd.test']);
        Http::fake(['https://ussd.test/*' => Http::response([], 500)]);
        $transactionId = (string) Str::uuid();
        UssdPaymentReport::query()->create(['client_reference' => $transactionId]);
        PaymentIntent::factory()->create(['client_reference' => $transactionId, 'status' => PaymentIntentStatus::Failed]);

        expect(fn () => (new ReportUssdTransactionStatus($transactionId))->handle())->toThrow(RequestException::class);
        expect(UssdPaymentReport::query()->find($transactionId)->reported_at)->toBeNull();
    });

    it('does not let a USSD service user record for another company', function (): void {
        $otherBranch = Branch::factory()->create();
        $otherServiceUser = app(UssdServiceUser::class)->forCompany((string) $otherBranch->company_id);

        expect(fn () => app(RecordCollectionAction::class)->assertRecordable($otherServiceUser, $this->account, 500))
            ->toThrow(ValidationException::class);
    });
});

it('returns 404 for an unknown action', function (): void {
    ussdPost('/api/ussd/actions/susu.unknown', susuAction($this->customer))->assertNotFound();
});
