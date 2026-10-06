<?php

use App\Actions\Reports\BuildCompanyUsageReportAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Filament\SuperAdmin\Pages\CompanyUsageReport;
use App\Filament\SuperAdmin\Pages\PlatformAuditLog;
use App\Filament\SuperAdmin\Widgets\CompaniesNeedingAttention;
use App\Filament\SuperAdmin\Widgets\PlatformOverview;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();
    $this->superAdmin = User::factory()->superAdmin()->create();
    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();

    $this->company = Company::factory()->create();
    $this->branch = Branch::factory()->for($this->company)->create();
    $this->admin = User::factory()->companyAdmin($this->company)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'agent_id' => $this->agent->id,
    ]);
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 2_500);
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 1_500);
});

it('aggregates each company\'s size, book and month-to-date collections', function (): void {
    $other = Company::factory()->create();

    $rows = app(BuildCompanyUsageReportAction::class)->execute()->keyBy('id');
    $mine = $rows[$this->company->id];

    expect($mine->branches_count)->toBe(1)
        ->and($mine->staff_count)->toBe(2)
        ->and($mine->company_admins_count)->toBe(1)
        ->and($mine->customers_count)->toBe(1)
        ->and($mine->new_customers_count)->toBe(1)
        ->and($mine->active_savings_accounts_count)->toBe(1)
        ->and((int) $mine->savings_balance)->toBe((int) $this->account->refresh()->balance)
        ->and($mine->collections_count)->toBe(2)
        ->and((int) $mine->collections_amount)->toBe(4_000)
        ->and($mine->last_activity_at)->not->toBeNull()
        ->and($rows[$other->id]->collections_count)->toBe(0)
        ->and((int) $rows[$other->id]->collections_amount)->toBe(0);
});

it('leaves collections outside the period out of the totals', function (): void {
    $rows = app(BuildCompanyUsageReportAction::class)
        ->execute(now()->subMonths(3)->toImmutable(), now()->subMonths(2)->toImmutable())
        ->keyBy('id');

    expect($rows[$this->company->id]->collections_count)->toBe(0)
        ->and($rows[$this->company->id]->new_customers_count)->toBe(0)
        ->and($rows[$this->company->id]->customers_count)->toBe(1);
});

it('renders the company usage report page', function (): void {
    livewire(CompanyUsageReport::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->company]);
});

it('downloads the company usage report for super admins only', function (): void {
    $this->get(route('platform-reports.company-usage.excel'))->assertOk();
    $this->get(route('platform-reports.company-usage.pdf', ['from' => now()->startOfMonth()->toDateString()]))->assertOk();

    $this->actingAs($this->admin);

    $this->get(route('platform-reports.company-usage.excel'))->assertForbidden();
    $this->get(route('platform-reports.company-usage.pdf'))->assertForbidden();
});

it('lists companies with incomplete onboarding or no recent activity', function (): void {
    $noBranch = Company::factory()->create();
    $dormant = Company::factory()->create();
    Branch::factory()->for($dormant)->create();
    User::factory()->companyAdmin($dormant)->create();
    $suspended = Company::factory()->inactive()->create();

    livewire(CompaniesNeedingAttention::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$noBranch, $dormant])
        ->assertCanNotSeeTableRecords([$this->company, $suspended]);
});

it('shows platform-wide customers and collections on the overview', function (): void {
    livewire(PlatformOverview::class)
        ->assertOk()
        ->assertSee('Customers')
        ->assertSee('Collections (This Month)');
});

it('shows company and branch changes in the platform audit log, filterable by company', function (): void {
    $other = Company::factory()->create();
    Customer::factory()->create(['company_id' => $other->id, 'branch_id' => Branch::factory()->for($other)->create()->id]);

    livewire(PlatformAuditLog::class)
        ->assertOk()
        ->filterTable('company', $this->company->id)
        ->assertSee($this->company->name)
        ->assertSee($this->branch->name);
});
