<?php

use App\Enums\ClientOrigin;
use App\Enums\SyncOpType;
use App\Filament\SuperAdmin\Pages\Devices;
use App\Filament\SuperAdmin\Pages\SyncHealth;
use App\Filament\SuperAdmin\Widgets\SyncHealthOverview;
use App\Models\Branch;
use App\Models\SyncOp;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function (): void {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
});

it('records the app platform and version a device reports', function (): void {
    $token = $this->agent->createToken('Pixel 7');

    $this->withToken($token->plainTextToken)
        ->withHeaders(['X-Client-Platform' => 'Mobile', 'X-App-Version' => '1.4.0'])
        ->getJson('/api/v1/auth/me')
        ->assertOk();

    $record = PersonalAccessToken::findOrFail($token->accessToken->id);
    expect($record->client_platform)->toBe('mobile')
        ->and($record->client_version)->toBe('1.4.0');
});

it('falls back to X-Client-Origin for older desktop builds and ignores missing headers', function (): void {
    $token = $this->agent->createToken('susudesktop');

    $this->withToken($token->plainTextToken)->withHeaders(['X-Client-Origin' => 'desktop'])->getJson('/api/v1/auth/me')->assertOk();
    $this->withToken($token->plainTextToken)->getJson('/api/v1/auth/me')->assertOk();

    expect(PersonalAccessToken::findOrFail($token->accessToken->id)->client_platform)->toBe('desktop');
});

it('lists devices and lets a super admin sign one out', function (): void {
    $token = $this->agent->createToken('Old phone');
    $token->accessToken->forceFill(['last_used_at' => now()->subDays(10)])->save();

    $this->actingAs(User::factory()->superAdmin()->create());
    bootSuperAdminPanel();

    livewire(Devices::class)
        ->assertCanSeeTableRecords([$token->accessToken])
        ->filterTable('stale')
        ->assertCanSeeTableRecords([$token->accessToken])
        ->callAction(TestAction::make('signOut')->table($token->accessToken))
        ->assertNotified();

    expect($this->agent->tokens()->count())->toBe(0);
});

it('shows rejected sync operations by default with their errors', function (): void {
    $rejected = SyncOp::create([
        'op_id' => (string) Str::uuid(),
        'company_id' => $this->agent->company_id,
        'actor_id' => $this->agent->id,
        'origin' => ClientOrigin::Mobile,
        'op_type' => SyncOpType::cases()[0],
        'status' => 'rejected',
        'result' => ['errors' => ['Customer limit reached.']],
        'recorded_at' => now(),
    ]);
    $applied = SyncOp::create([
        'op_id' => (string) Str::uuid(),
        'company_id' => $this->agent->company_id,
        'actor_id' => $this->agent->id,
        'origin' => ClientOrigin::Mobile,
        'op_type' => SyncOpType::cases()[0],
        'status' => 'applied',
        'result' => [],
        'recorded_at' => now(),
    ]);

    $this->actingAs(User::factory()->superAdmin()->create());
    bootSuperAdminPanel();

    livewire(SyncHealth::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$rejected])
        ->assertCanNotSeeTableRecords([$applied])
        ->assertSee('Customer limit reached.');

    livewire(SyncHealthOverview::class)
        ->assertOk()
        ->assertSee('50% of all operations');
});
