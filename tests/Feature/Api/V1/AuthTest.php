<?php

use App\Models\Branch;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();
});

it('logs in with email and returns a token with the role ability', function (): void {
    $branch = Branch::factory()->create();
    $agent = User::factory()->fieldAgent($branch)->create([
        'email' => 'agent@example.com',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'login' => 'agent@example.com',
        'password' => 'password',
        'device_name' => 'pest-device',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'role', 'company', 'branches']])
        ->assertJsonPath('user.role', 'field_agent');

    expect($agent->tokens()->first()->abilities)->toContain('role:field_agent');
});

it('logs in with phone', function (): void {
    $branch = Branch::factory()->create();
    User::factory()->fieldAgent($branch)->create([
        'phone' => '+233241234567',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'login' => '+233241234567',
        'password' => 'password',
        'device_name' => 'pest-device',
    ])->assertOk()->assertJsonPath('user.phone', '+233241234567');
});

it('rejects invalid credentials', function (): void {
    User::factory()->create(['email' => 'user@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'login' => 'user@example.com',
        'password' => 'wrong-password',
        'device_name' => 'pest-device',
    ])->assertUnprocessable()->assertJsonValidationErrors('login');
});

it('rejects deactivated accounts', function (): void {
    User::factory()->inactive()->create(['email' => 'gone@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'login' => 'gone@example.com',
        'password' => 'password',
        'device_name' => 'pest-device',
    ])->assertUnprocessable()->assertJsonValidationErrors('login');
});

it('returns the authenticated user from /auth/me', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create();

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.role', 'branch_manager')
        ->assertJsonPath('user.branches.0.id', $branch->id);
});

it('revokes the current token on logout', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('pest-device', ['role:none']);

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('requires authentication for /auth/me', function (): void {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('rejects users of a suspended company at login', function (): void {
    $branch = Branch::factory()->create();
    User::factory()->fieldAgent($branch)->create(['email' => 'agent@suspended.test']);
    $branch->company->update(['is_active' => false]);

    $this->postJson('/api/v1/auth/login', [
        'login' => 'agent@suspended.test',
        'password' => 'password',
        'device_name' => 'pest-device',
    ])->assertUnprocessable()->assertJsonValidationErrors('login');
});

it('revokes an existing token once the company is suspended', function (): void {
    $branch = Branch::factory()->create();
    $agent = User::factory()->fieldAgent($branch)->create();
    $token = $agent->createToken('phone')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    $branch->company->update(['is_active' => false]);
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

    expect($agent->tokens()->count())->toBe(0);
});

it('revokes an existing token once the user is deactivated', function (): void {
    $branch = Branch::factory()->create();
    $agent = User::factory()->fieldAgent($branch)->create();
    $token = $agent->createToken('phone')->plainTextToken;

    $agent->update(['is_active' => false]);

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'This account has been deactivated.');
});
