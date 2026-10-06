<?php

use App\Actions\Company\CreateCompanyAdminAction;
use App\Actions\Staff\IssueTemporaryPasswordAction;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Filament\SuperAdmin\Resources\Users\Pages\ListUsers;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Notifications\StaffAccountCredentials;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    seedRoles();
    Notification::fake();
});

it('sends a new company admin their temporary sign-in details and flags the account', function (): void {
    $company = Company::factory()->create();
    Branch::factory()->for($company)->create();

    $admin = app(CreateCompanyAdminAction::class)->execute($company, [
        'name' => 'Esi Asante',
        'email' => 'esi@example.test',
        'phone' => '+233244555666',
    ]);

    expect($admin->must_change_password)->toBeTrue();

    Notification::assertSentTo($admin, StaffAccountCredentials::class, function (StaffAccountCredentials $notification, array $channels) use ($admin): bool {
        return $notification->reason === StaffAccountCredentials::REASON_WELCOME
            && Hash::check($notification->temporaryPassword, $admin->password)
            && count($channels) === 2;
    });
});

it('redirects a user on a temporary password to their profile until they change it', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create(['must_change_password' => true]);

    $this->actingAs($manager)
        ->get('/admin/'.$branch->slug)
        ->assertRedirect('/admin/profile');

    $this->actingAs($manager)->get('/admin/profile')->assertOk();
});

it('clears the flag once the user saves a new password on the profile page', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create(['must_change_password' => true]);
    $this->actingAs($manager);
    bootAdminPanelWithTenant($branch);

    livewire(EditProfile::class)
        ->fillForm([
            'password' => 'my-own-password',
            'passwordConfirmation' => 'my-own-password',
            'currentPassword' => 'password',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($manager->refresh()->must_change_password)->toBeFalse()
        ->and(Hash::check('my-own-password', $manager->password))->toBeTrue();
});

it('requires a new password on the profile page while the flag is set', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create(['must_change_password' => true]);
    $this->actingAs($manager);
    bootAdminPanelWithTenant($branch);

    livewire(EditProfile::class)
        ->fillForm(['password' => null])
        ->call('save')
        ->assertHasFormErrors(['password' => 'required']);
});

it('blocks other API endpoints until the temporary password is changed', function (): void {
    $branch = Branch::factory()->create();
    $agent = User::factory()->fieldAgent($branch)->create(['must_change_password' => true]);
    $token = $agent->createToken('phone')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.must_change_password', true);

    $this->withToken($token)->getJson('/api/v1/dashboard/agent')
        ->assertForbidden()
        ->assertJsonPath('code', 'password_change_required');

    $this->withToken($token)->putJson('/api/v1/auth/password', [
        'current_password' => 'password',
        'password' => 'brand-new-pass',
        'password_confirmation' => 'brand-new-pass',
    ])->assertOk()->assertJsonPath('user.must_change_password', false);

    expect($agent->refresh()->must_change_password)->toBeFalse();
});

it('rejects a wrong current password on the API', function (): void {
    $branch = Branch::factory()->create();
    $agent = User::factory()->fieldAgent($branch)->create();

    $this->actingAs($agent, 'sanctum')->putJson('/api/v1/auth/password', [
        'current_password' => 'not-it',
        'password' => 'brand-new-pass',
        'password_confirmation' => 'brand-new-pass',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
});

it('lets a super admin reset a staff password, signing them out of the apps', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $branch = Branch::factory()->create();
    $agent = User::factory()->fieldAgent($branch)->create();
    $agent->createToken('phone');

    $this->actingAs($superAdmin);
    bootSuperAdminPanel();

    livewire(ListUsers::class)
        ->callAction(TestAction::make('resetPassword')->table($agent))
        ->assertNotified();

    $agent->refresh();

    expect($agent->must_change_password)->toBeTrue()
        ->and($agent->tokens()->count())->toBe(0)
        ->and(Hash::check('password', $agent->password))->toBeFalse();

    Notification::assertSentTo($agent, StaffAccountCredentials::class, fn (StaffAccountCredentials $notification): bool => $notification->reason === StaffAccountCredentials::REASON_RESET);
});

it('lets a company admin reset their staff password', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $admin = User::factory()->companyAdmin($company)->create();
    $agent = User::factory()->fieldAgent($branch)->create();

    $this->actingAs($admin);
    bootAdminPanelWithTenant($branch);

    livewire(ListStaff::class)
        ->callAction(TestAction::make('resetPassword')->table($agent))
        ->assertNotified();

    expect($agent->refresh()->must_change_password)->toBeTrue();
});

it('generates a temporary password of reasonable strength', function (): void {
    expect(strlen(IssueTemporaryPasswordAction::generatePassword()))->toBe(10);
});
