<?php

use App\Actions\Billing\RecordInvoicePaymentAction;
use App\Actions\Billing\SubscribeCompanyAction;
use App\Actions\Reports\BuildCompanyActivityTrendAction;
use App\Actions\Reports\BuildCompanyRiskReportAction;
use App\Actions\Reports\BuildRevenueReportAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\BillingPeriod;
use App\Enums\LicenseSaleStatus;
use App\Filament\SuperAdmin\Pages\CompanyRiskReport;
use App\Filament\SuperAdmin\Pages\RevenueReport;
use App\Filament\SuperAdmin\Widgets\CompanyActivityChart;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DesktopLicenseSale;
use App\Models\Plan;
use App\Models\SavingsAccount;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    seedRoles();
    Notification::fake();
    $this->actingAs(User::factory()->superAdmin()->create());
    bootSuperAdminPanel();
});

it('reports monthly revenue, MRR and receivables', function (): void {
    $monthly = Company::factory()->create();
    $yearly = Company::factory()->create();
    $paid = app(SubscribeCompanyAction::class)->execute($monthly, Plan::factory()->create(['price_amount' => 300_00]));
    app(SubscribeCompanyAction::class)->execute($yearly, Plan::factory()->create(['price_amount' => 1_200_00, 'billing_period' => BillingPeriod::Yearly]));
    app(RecordInvoicePaymentAction::class)->execute($paid->invoices()->sole(), 'cash');
    DesktopLicenseSale::factory()->create(['status' => LicenseSaleStatus::Issued, 'amount' => 500_00, 'created_at' => now()]);

    $report = app(BuildRevenueReportAction::class)->execute();
    $thisMonth = end($report['months']);

    expect($thisMonth['subscriptions'])->toBe(300_00)
        ->and($thisMonth['licenses'])->toBe(500_00)
        ->and($thisMonth['total'])->toBe(800_00)
        ->and($report['mrr'])->toBe(300_00 + 100_00)
        ->and($report['outstanding'])->toBe(1_200_00)
        ->and($report['overdue'])->toBe(0);

    livewire(RevenueReport::class)->assertOk()->assertSee('Monthly recurring revenue');
});

it('reports portfolio at risk and agent activity per company', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $working = User::factory()->fieldAgent($branch)->create();
    User::factory()->fieldAgent($branch)->create();
    $account = SavingsAccount::factory()->create(['branch_id' => $branch->id, 'agent_id' => $working->id]);
    app(RecordCollectionAction::class)->execute($working, $account, 1_000);
    app(RecordCollectionAction::class)->execute($working, $account, 1_000);

    $row = app(BuildCompanyRiskReportAction::class)->execute()[$company->id];

    expect($row['agents'])->toBe(2)
        ->and($row['active_agents'])->toBe(1)
        ->and($row['collections'])->toBe(2)
        ->and($row['collections_amount'])->toBe(2_000)
        ->and($row['per_active_agent'])->toBe(2_000)
        ->and($row['par_percent'])->toBe(0.0);

    livewire(CompanyRiskReport::class)->assertOk()->assertSee($company->name);
});

it('tracks active versus total companies per month', function (): void {
    $company = Company::factory()->create();
    Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $agent = User::factory()->fieldAgent($branch)->create();
    $account = SavingsAccount::factory()->create(['branch_id' => $branch->id, 'agent_id' => $agent->id]);
    app(RecordCollectionAction::class)->execute($agent, $account, 500);

    $months = app(BuildCompanyActivityTrendAction::class)->execute();
    $thisMonth = end($months);

    expect($months)->toHaveCount(12)
        ->and($thisMonth['active'])->toBe(1)
        ->and($thisMonth['total'])->toBe(Company::count())
        ->and($thisMonth['new'])->toBe(Company::count());

    livewire(CompanyActivityChart::class)->assertOk();
});
