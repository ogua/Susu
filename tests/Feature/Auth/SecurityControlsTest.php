<?php

use App\Filament\SuperAdmin\Pages\PlatformAuditLog;
use App\Filament\SuperAdmin\Resources\Users\Pages\ListUsers;
use App\Models\Branch;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\TwoFactorCode;
use App\Services\Auth\EmailAndSmsAuthentication;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use STS\FilamentImpersonate\Facades\Impersonation;

beforeEach(function (): void {
    seedRoles();
    $this->superAdmin = User::factory()->superAdmin()->create();
});

it('requires super admins to set up two-factor authentication', function (): void {
    $this->actingAs($this->superAdmin)
        ->get('/super-admin')
        ->assertRedirectContains('multi-factor-authentication/set-up');
});

it('lets a super admin with two-factor set up into the panel', function (): void {
    $this->superAdmin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    $this->actingAs($this->superAdmin)->get('/super-admin')->assertOk();
});

it('keeps two-factor optional for tenant staff', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create();

    $this->actingAs($manager)->get('/admin/profile')->assertOk();
});

it('stores the authenticator secret encrypted and never serializes it', function (): void {
    $this->superAdmin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    $raw = DB::table('users')->where('id', $this->superAdmin->id)->value('app_authentication_secret');

    expect($raw)->not->toBe('JBSWY3DPEHPK3PXP')
        ->and($this->superAdmin->refresh()->toArray())->not->toHaveKey('app_authentication_secret');
});

it('logs every impersonation in the platform audit log', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create();
    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();

    livewire(ListUsers::class)->callTableAction('impersonate', $manager);

    expect(Impersonation::isImpersonating())->toBeTrue();

    $entry = Activity::where('log_name', 'impersonation')->sole();
    expect($entry->event)->toBe('impersonation_started')
        ->and($entry->causer_id)->toBe($this->superAdmin->id)
        ->and($entry->subject_id)->toBe($manager->id);

    livewire(PlatformAuditLog::class)
        ->filterTable('event', 'impersonation_started')
        ->assertCanSeeTableRecords([$entry]);
});

it('lets a super admin reset a user\'s two-factor and logs it', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create();
    $manager->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $manager->saveAppAuthenticationRecoveryCodes(['code-1']);
    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();

    livewire(ListUsers::class)
        ->callAction(TestAction::make('resetTwoFactor')->table($manager))
        ->assertNotified();

    $manager->refresh();
    expect($manager->app_authentication_secret)->toBeNull()
        ->and($manager->app_authentication_recovery_codes)->toBeNull()
        ->and(Activity::where('event', 'two_factor_reset')->exists())->toBeTrue();
});

it('sends two-factor codes to both email and phone, and verifies them', function (): void {
    Notification::fake();
    $this->superAdmin->update(['phone' => '+233244000111']);
    $provider = EmailAndSmsAuthentication::make();

    $this->actingAs($this->superAdmin);
    expect($provider->sendCode($this->superAdmin))->toBeTrue();

    $sentCode = null;
    Notification::assertSentTo($this->superAdmin, TwoFactorCode::class, function (TwoFactorCode $notification, array $channels) use (&$sentCode): bool {
        $sentCode = $notification->code;

        return $channels === ['mail', SmsChannel::class];
    });

    expect($provider->verifyCode('000000x'))->toBeFalse()
        ->and($provider->verifyCode($sentCode))->toBeTrue()
        ->and($provider->verifyCode($sentCode))->toBeFalse();
});

it('accepts email and SMS codes as a super admin\'s required two-factor method', function (): void {
    $this->superAdmin->toggleEmailAuthentication(true);

    $this->actingAs($this->superAdmin)->get('/super-admin')->assertOk();
});
