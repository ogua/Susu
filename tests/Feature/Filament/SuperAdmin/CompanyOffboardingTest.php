<?php

use App\Actions\Billing\SubscribeCompanyAction;
use App\Actions\Company\ArchiveCompanyAction;
use App\Actions\Company\ErasePersonalDataAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\SuperAdmin\Resources\Companies\Pages\ListCompanies;
use App\Filament\SuperAdmin\Resources\Companies\Pages\ViewCompany;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyExport;
use App\Models\Customer;
use App\Models\CustomerIdentification;
use App\Models\Plan;
use App\Models\SavingsAccount;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();
    Notification::fake();
    Storage::fake('local');
    Storage::fake('public');

    $this->superAdmin = User::factory()->superAdmin()->create();
    $this->company = Company::factory()->create(['name' => 'Leaving Co']);
    $this->branch = Branch::factory()->for($this->company)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create(['name' => 'Kwame Agent']);
    $this->customer = Customer::factory()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'first_name' => 'Akosua',
        'last_name' => 'Owusu',
        'id_number' => 'GHA-123456789-0',
    ]);
});

it('exports every company record to a zip of readable CSVs without secrets', function (): void {
    $account = SavingsAccount::factory()->create(['branch_id' => $this->branch->id, 'customer_id' => $this->customer->id, 'agent_id' => $this->agent->id]);
    app(RecordCollectionAction::class)->execute($this->agent, $account, 1_000);
    Company::factory()->create(['name' => 'Someone Else']);

    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();

    livewire(ViewCompany::class, ['record' => $this->company->getKey()])
        ->callAction('exportData')
        ->assertNotified();

    $export = CompanyExport::where('company_id', $this->company->id)->sole();
    expect($export->status)->toBe(CompanyExport::STATUS_READY);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));
    $customers = $zip->getFromName('customers.csv');
    $users = $zip->getFromName('users.csv');
    $companies = $zip->getFromName('company.csv');

    expect($customers)->toContain('Akosua')->toContain('GHA-123456789-0')
        ->and($users)->toContain('Kwame Agent')->not->toContain('$2y$')->not->toContain('remember_token')
        ->and($companies)->not->toContain('Someone Else')
        ->and($zip->getFromName('journal_lines.csv'))->not->toBeFalse();

    $this->get(route('platform-exports.download', $export))->assertOk();
});

it('refuses export downloads to anyone but a super admin', function (): void {
    $export = CompanyExport::create(['company_id' => $this->company->id, 'status' => CompanyExport::STATUS_READY, 'path' => 'exports/x.zip']);
    $admin = User::factory()->companyAdmin($this->company)->create();

    $this->actingAs($admin)->get(route('platform-exports.download', $export))->assertForbidden();
});

it('archives a company: signs everyone out, cancels billing and hides it from the list', function (): void {
    $subscription = app(SubscribeCompanyAction::class)->execute($this->company, Plan::factory()->create());
    $this->agent->createToken('phone');

    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();

    livewire(ViewCompany::class, ['record' => $this->company->getKey()])
        ->callAction('archive')
        ->assertNotified();

    $this->company->refresh();
    expect($this->company->archived_at)->not->toBeNull()
        ->and($this->company->is_active)->toBeFalse()
        ->and($this->company->suspended_reason)->toBe(Company::SUSPENDED_ARCHIVED)
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->invoices()->sole()->status)->toBe(InvoiceStatus::Void)
        ->and($this->agent->tokens()->count())->toBe(0);

    livewire(ListCompanies::class)
        ->assertCanNotSeeTableRecords([$this->company])
        ->filterTable('archived', true)
        ->assertCanSeeTableRecords([$this->company]);

    app(ArchiveCompanyAction::class)->restore($this->company);
    expect($this->company->refresh()->archived_at)->toBeNull()
        ->and($this->company->is_active)->toBeFalse();
});

it('only erases personal data after the retention period', function (): void {
    config(['platform.data_retention_days' => 30]);
    app(ArchiveCompanyAction::class)->archive($this->company);

    expect(fn () => app(ErasePersonalDataAction::class)->execute($this->company->refresh()))
        ->toThrow(ValidationException::class);

    $this->travel(31)->days();
    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();

    livewire(ViewCompany::class, ['record' => $this->company->getKey()])
        ->callAction('erasePersonalData', ['confirm_name' => 'Wrong name'])
        ->assertHasActionErrors(['confirm_name']);

    CustomerIdentification::create(['customer_id' => $this->customer->id, 'id_type' => 'ghana_card', 'id_number' => 'GHA-1']);

    livewire(ViewCompany::class, ['record' => $this->company->getKey()])
        ->callAction('erasePersonalData', ['confirm_name' => 'Leaving Co'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $customer = Customer::withTrashed()->find($this->customer->id);
    $agent = $this->agent->refresh();

    expect($customer->first_name)->toBe('Erased')
        ->and($customer->id_number)->toBeNull()
        ->and($customer->identifications()->count())->toBe(0)
        ->and($agent->name)->toBe('Erased user')
        ->and($agent->is_active)->toBeFalse()
        ->and($this->company->refresh()->personal_data_erased_at)->not->toBeNull();

    expect(fn () => app(ArchiveCompanyAction::class)->restore($this->company))->toThrow(ValidationException::class);
});
