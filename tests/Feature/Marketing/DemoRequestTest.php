<?php

use App\Enums\DemoOrganisationType;
use App\Filament\SuperAdmin\Resources\DemoRequests\Pages\ListDemoRequests;
use App\Http\Requests\Marketing\StoreDemoRequestRequest;
use App\Models\DemoRequest;
use App\Models\User;
use App\Notifications\DemoRequestReceived;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;

/**
 * @return array<string, mixed>
 */
function demoRequestPayload(array $overrides = []): array
{
    return [
        'name' => 'Abena Owusu',
        'organisation' => 'Owusu Susu Enterprise',
        'email' => 'abena@example.com',
        'phone' => '+233 24 123 4567',
        'organisation_type' => DemoOrganisationType::SusuEnterprise->value,
        'branches_count' => 3,
        'message' => 'Eight agents on paper cards.',
        StoreDemoRequestRequest::STARTED_FIELD => encrypt(now()->subMinute()->timestamp),
        ...$overrides,
    ];
}

beforeEach(function (): void {
    seedRoles();
    Notification::fake();
    $this->superAdmin = User::factory()->superAdmin()->create();
});

it('shows the demo form', function (): void {
    $this->get('/demo')
        ->assertOk()
        ->assertSee('name="'.StoreDemoRequestRequest::HONEYPOT_FIELD.'"', false)
        ->assertSee(DemoOrganisationType::Microfinance->getLabel());
});

it('stores a demo request and notifies the super admins', function (): void {
    $this->post('/demo', demoRequestPayload())
        ->assertRedirect(route('marketing.demo'))
        ->assertSessionHas('demoRequestSent');

    $demoRequest = DemoRequest::sole();

    expect($demoRequest->organisation)->toBe('Owusu Susu Enterprise')
        ->and($demoRequest->organisation_type)->toBe(DemoOrganisationType::SusuEnterprise)
        ->and($demoRequest->handled_at)->toBeNull();

    Notification::assertSentTo($this->superAdmin, DemoRequestReceived::class);
});

it('validates the required fields', function (): void {
    $this->post('/demo', demoRequestPayload(['name' => '', 'email' => 'not-an-email', 'organisation_type' => 'bank', 'phone' => 'abc']))
        ->assertSessionHasErrors(['name', 'email', 'organisation_type', 'phone']);

    expect(DemoRequest::count())->toBe(0);
});

it('silently drops automated submissions', function (Closure $overrides): void {
    $this->post('/demo', demoRequestPayload($overrides()))
        ->assertRedirect(route('marketing.demo'))
        ->assertSessionHas('demoRequestSent');

    expect(DemoRequest::count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    // Closures: encrypt() needs the booted app, which datasets resolve before.
    'honeypot filled' => [fn (): array => [StoreDemoRequestRequest::HONEYPOT_FIELD => 'https://spam.example']],
    'submitted too fast' => [fn (): array => [StoreDemoRequestRequest::STARTED_FIELD => encrypt(now()->timestamp)]],
    'tampered timestamp' => [fn (): array => [StoreDemoRequestRequest::STARTED_FIELD => 'not-encrypted']],
]);

it('ignores a repeat from the same email within the duplicate window', function (): void {
    $this->post('/demo', demoRequestPayload());
    $this->post('/demo', demoRequestPayload(['message' => 'Sent again']));

    expect(DemoRequest::count())->toBe(1);
    Notification::assertSentToTimes($this->superAdmin, DemoRequestReceived::class, 1);
});

it('throttles repeated submissions', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->post('/demo', demoRequestPayload(['email' => "lead{$attempt}@example.com"]))->assertRedirect();
    }

    $this->post('/demo', demoRequestPayload(['email' => 'lead6@example.com']))->assertTooManyRequests();
});

it('lets a super admin list demo requests and mark one handled', function (): void {
    $open = DemoRequest::factory()->create();
    $handled = DemoRequest::factory()->handled()->create();

    bootSuperAdminPanel();
    $this->actingAs($this->superAdmin);

    livewire(ListDemoRequests::class)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$handled])
        ->callAction(TestAction::make('markHandled')->table($open))
        ->assertNotified();

    expect($open->fresh()->handled_at)->not->toBeNull()
        ->and($open->fresh()->handled_by)->toBe($this->superAdmin->id);
});
