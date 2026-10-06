<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Notifications\TwoFactorCode;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    seedRoles();
    Notification::fake();
    $this->company = Company::factory()->create();
    Branch::factory()->for($this->company)->create();
});

function apiLogin(string $login, string $password = 'password'): TestResponse
{
    return test()->postJson('/api/v1/auth/login', ['login' => $login, 'password' => $password, 'device_name' => 'pest']);
}

it('lets a field agent without two-factor sign straight in', function (): void {
    $agent = User::factory()->fieldAgent(Branch::first())->create(['email' => 'agent@x.test']);

    apiLogin('agent@x.test')->assertOk()->assertJsonStructure(['token']);
});

it('challenges a company admin and sends the code to email and phone', function (): void {
    $admin = User::factory()->companyAdmin($this->company)->create(['email' => 'boss@x.test', 'phone' => '+233244123456']);

    $response = apiLogin('boss@x.test')
        ->assertOk()
        ->assertJsonPath('two_factor_required', true)
        ->assertJsonPath('methods', ['code'])
        ->assertJsonPath('code_sent_to.phone', '•••456')
        ->assertJsonMissingPath('token');

    $code = null;
    Notification::assertSentTo($admin, TwoFactorCode::class, function (TwoFactorCode $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    $this->postJson('/api/v1/auth/login/two-factor', [
        'challenge_token' => $response->json('challenge_token'),
        'code' => 'not-the-code',
        'device_name' => 'pest',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');

    $this->postJson('/api/v1/auth/login/two-factor', [
        'challenge_token' => $response->json('challenge_token'),
        'code' => $code,
        'device_name' => 'pest',
    ])->assertOk()->assertJsonStructure(['token', 'user' => ['id']]);

    expect($admin->tokens()->count())->toBe(1);

    $this->postJson('/api/v1/auth/login/two-factor', [
        'challenge_token' => $response->json('challenge_token'),
        'code' => $code,
        'device_name' => 'pest',
    ])->assertUnprocessable();
});

it('accepts an authenticator app code for users who set one up', function (): void {
    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();
    $manager = User::factory()->branchManager(Branch::first())->create(['email' => 'mgr@x.test']);
    $manager->saveAppAuthenticationSecret($secret);

    $response = apiLogin('mgr@x.test')
        ->assertJsonPath('two_factor_required', true)
        ->assertJsonPath('methods', ['app', 'recovery_code', 'code']);

    Notification::assertNothingSent();

    $this->postJson('/api/v1/auth/login/two-factor', [
        'challenge_token' => $response->json('challenge_token'),
        'code' => $google2fa->getCurrentOtp($secret),
        'device_name' => 'pest',
    ])->assertOk()->assertJsonStructure(['token']);
});

it('can send an email/SMS code to an authenticator-app user who asks for one', function (): void {
    $manager = User::factory()->branchManager(Branch::first())->create(['email' => 'mgr2@x.test']);
    $manager->saveAppAuthenticationSecret((new Google2FA)->generateSecretKey());

    $token = apiLogin('mgr2@x.test')->json('challenge_token');

    $this->postJson('/api/v1/auth/login/two-factor/resend', ['challenge_token' => $token])->assertOk();

    Notification::assertSentTo($manager, TwoFactorCode::class);
});

it('locks the challenge after too many wrong codes', function (): void {
    User::factory()->companyAdmin($this->company)->create(['email' => 'boss2@x.test']);
    $token = apiLogin('boss2@x.test')->json('challenge_token');

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login/two-factor', ['challenge_token' => $token, 'code' => 'nope', 'device_name' => 'pest'])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login/two-factor', ['challenge_token' => $token, 'code' => 'nope', 'device_name' => 'pest'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'Too many wrong codes. Sign in again.');
});

it('starts a silent challenge without sending a code', function (): void {
    User::factory()->companyAdmin($this->company)->create(['email' => 'boss3@x.test']);

    $this->postJson('/api/v1/auth/login', ['login' => 'boss3@x.test', 'password' => 'password', 'device_name' => 'desk', 'silent' => true])
        ->assertOk()
        ->assertJsonPath('two_factor_required', true)
        ->assertJsonPath('code_sent_to.email', null);

    Notification::assertNothingSent();
});
