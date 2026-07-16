<?php

use App\Actions\Savings\RecordCollectionAction;
use App\Filament\Pages\AgentPerformanceReport;
use App\Filament\Pages\BalanceSheet;
use App\Filament\Pages\CollectionsReport;
use App\Filament\Pages\CustomerBalancesReport;
use App\Filament\Pages\GeneralLedgerReport;
use App\Filament\Pages\GroupReport;
use App\Filament\Pages\IncomeStatement;
use App\Filament\Pages\LoanPortfolioReport;
use App\Filament\Pages\WithdrawalsReport;
use App\Filament\SuperAdmin\Widgets\CompanyGrowthChart;
use App\Filament\SuperAdmin\Widgets\LicenseRevenueChart;
use App\Filament\Widgets\CollectionsTrendChart;
use App\Filament\Widgets\LoanPortfolioChart;
use App\Filament\Widgets\SavingsVsWithdrawalsChart;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $product = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
    ]);
    $customer = Customer::factory()->forBranch($this->branch)->create();
    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $customer->id,
        'savings_product_id' => $product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);
});

it('renders every report page for a branch manager', function (string $pageClass): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire($pageClass)->assertOk();
})->with([
    CollectionsReport::class,
    LoanPortfolioReport::class,
    WithdrawalsReport::class,
    CustomerBalancesReport::class,
    GroupReport::class,
    GeneralLedgerReport::class,
    AgentPerformanceReport::class,
    IncomeStatement::class,
    BalanceSheet::class,
]);

it('denies field agents every report page', function (string $pageClass): void {
    $this->actingAs($this->agent);

    expect($pageClass::canAccess())->toBeFalse();
})->with([
    CollectionsReport::class,
    LoanPortfolioReport::class,
    WithdrawalsReport::class,
    CustomerBalancesReport::class,
    GroupReport::class,
    GeneralLedgerReport::class,
    AgentPerformanceReport::class,
    IncomeStatement::class,
    BalanceSheet::class,
]);

it('shows recorded collections on the collections report page', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire(CollectionsReport::class)
        ->assertOk()
        ->assertSee($this->agent->name);
});

it('renders the admin chart widgets with data', function (string $widgetClass): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire($widgetClass)->assertOk();
})->with([
    CollectionsTrendChart::class,
    LoanPortfolioChart::class,
    SavingsVsWithdrawalsChart::class,
]);

it('builds a 30-point collections trend dataset', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    $widget = new CollectionsTrendChart;
    $method = new ReflectionMethod(CollectionsTrendChart::class, 'getData');
    $data = $method->invoke($widget);

    expect($data['labels'])->toHaveCount(30)
        ->and($data['datasets'][0]['data'])->toHaveCount(30)
        ->and(end($data['datasets'][0]['data']))->toBe(5.0);
});

it('renders the super admin chart widgets', function (string $widgetClass): void {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin);
    bootSuperAdminPanel();

    livewire($widgetClass)->assertOk();
})->with([
    [CompanyGrowthChart::class],
    [LicenseRevenueChart::class],
]);
