<?php

use App\Actions\Billing\SubscribeCompanyAction;
use App\Actions\Company\ExportCompanyDataAction;
use App\Actions\Platform\BuildPlatformDigestAction;
use App\Jobs\GenerateCompanyExportJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyExport;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\PlatformAlert;
use App\Notifications\PlatformDigest;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    seedRoles();
    Notification::fake();
    $this->superAdmin = User::factory()->superAdmin()->create();
});

it('flags a billing run that has not happened and overdue invoices', function (): void {
    $company = Company::factory()->create();
    Branch::factory()->for($company)->create();
    app(SubscribeCompanyAction::class)->execute($company, Plan::factory()->create(['price_amount' => 250_00]));
    $this->travel(10)->days();

    $items = collect(app(BuildPlatformDigestAction::class)->execute()['items'])->keyBy('label');

    expect($items['Billing run']['alert'])->toBeTrue()
        ->and($items['Billing run']['value'])->toBe('Has never run')
        ->and($items['Overdue invoices']['alert'])->toBeTrue()
        ->and($items['Overdue invoices']['value'])->toContain('250.00');

    $this->artisan('billing:run')->assertSuccessful();

    $items = collect(app(BuildPlatformDigestAction::class)->execute()['items'])->keyBy('label');
    expect($items['Billing run']['alert'])->toBeFalse();
});

it('emails the digest to active super admins', function (): void {
    $inactive = User::factory()->superAdmin()->inactive()->create();

    $this->artisan('platform:digest')->assertSuccessful();

    Notification::assertSentTo($this->superAdmin, PlatformDigest::class);
    Notification::assertNotSentTo($inactive, PlatformDigest::class);

    $mail = (new PlatformDigest(app(BuildPlatformDigestAction::class)->execute()))->toMail($this->superAdmin);
    expect(implode("\n", $mail->introLines))->toContain('Billing run');
});

it('alerts super admins straight away when a data export fails', function (): void {
    $company = Company::factory()->create();
    $export = CompanyExport::create(['company_id' => $company->id, 'status' => CompanyExport::STATUS_PENDING]);
    $this->mock(ExportCompanyDataAction::class)
        ->shouldReceive('build')
        ->andReturnUsing(function (CompanyExport $export): CompanyExport {
            $export->update(['status' => CompanyExport::STATUS_FAILED, 'error' => 'Disk full']);

            return $export;
        });

    (new GenerateCompanyExportJob($export))->handle(app(ExportCompanyDataAction::class));

    Notification::assertSentTo($this->superAdmin, PlatformAlert::class, fn (PlatformAlert $alert): bool => str_contains($alert->body, 'Disk full'));
});
