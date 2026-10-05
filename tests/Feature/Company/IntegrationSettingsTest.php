<?php

use App\Filament\Pages\Integrations;
use App\Models\Branch;
use App\Models\CompanyPaymentSetting;
use App\Models\CompanySmsSetting;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->company = $this->branch->company;
    $this->admin = User::factory()->companyAdmin($this->company)->create();
});

it('saves Arkesel and Paystack settings over the API without ever returning the secrets', function (): void {
    $response = $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/company/integrations', [
        'sms_provider' => 'arkesel',
        'sms_sender_id' => 'MySusu',
        'sms_api_key' => 'arkesel-key',
        'paystack_public_key' => 'pk_test_abc',
        'paystack_secret_key' => 'sk_test_abc',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.sms.provider', 'arkesel')
        ->assertJsonPath('data.sms.api_key_set', true)
        ->assertJsonPath('data.paystack.uses_own_account', true)
        ->assertJsonPath('data.paystack.webhook_url', route('webhooks.paystack.company', $this->company))
        ->assertJsonMissing(['sk_test_abc'])
        ->assertJsonMissing(['arkesel-key']);

    $sms = CompanySmsSetting::where('company_id', $this->company->id)->first();
    expect($sms->api_key)->toBe('arkesel-key')
        ->and(CompanyPaymentSetting::where('company_id', $this->company->id)->first()->paystack_secret_key)->toBe('sk_test_abc');
});

it('keeps stored secrets when they are sent blank', function (): void {
    CompanyPaymentSetting::factory()->create(['company_id' => $this->company->id, 'paystack_secret_key' => 'sk_live_keep']);

    $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/company/integrations', [
        'paystack_public_key' => 'pk_live_new',
        'paystack_secret_key' => '',
    ])->assertOk();

    expect($this->company->paymentSetting()->first()->paystack_secret_key)->toBe('sk_live_keep');
});

it('refuses Arkesel without a sender ID and API key', function (): void {
    $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/company/integrations', [
        'sms_provider' => 'arkesel',
    ])->assertUnprocessable()->assertJsonValidationErrors('sms_provider');
});

it('rejects keys that are not Paystack keys', function (): void {
    $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/company/integrations', [
        'paystack_secret_key' => 'pk_wrong_slot',
    ])->assertUnprocessable()->assertJsonValidationErrors('paystack_secret_key');
});

it('disconnects Paystack', function (): void {
    CompanyPaymentSetting::factory()->create(['company_id' => $this->company->id]);

    $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/company/integrations', [
        'disconnect_paystack' => true,
    ])->assertOk()->assertJsonPath('data.paystack.uses_own_account', false);
});

it('forbids staff other than company admins', function (): void {
    $manager = User::factory()->branchManager($this->branch)->create();

    $this->actingAs($manager, 'sanctum')->getJson('/api/v1/company/integrations')->assertForbidden();
    $this->actingAs($manager, 'sanctum')->putJson('/api/v1/company/integrations', ['sms_provider' => 'log'])->assertForbidden();
});

it('saves settings from the web page', function (): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(Integrations::class)
        ->fillForm([
            'sms_provider' => 'arkesel',
            'sms_sender_id' => 'MySusu',
            'sms_api_key' => 'arkesel-key',
            'paystack_public_key' => 'pk_test_web',
            'paystack_secret_key' => 'sk_test_web',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($this->company->smsSetting()->first()->sender_id)->toBe('MySusu')
        ->and($this->company->paymentSetting()->first()->paystack_secret_key)->toBe('sk_test_web');
});

it('hides the page from non-admins', function (): void {
    $this->actingAs(User::factory()->branchManager($this->branch)->create());
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    expect(Integrations::canAccess())->toBeFalse();
});
