<?php

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Console\Commands\FlagLoanArrears;
use App\Filament\Widgets\AgentLeaderboard;
use App\Filament\Widgets\DashboardOverview;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Support\Money;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
});

// bootAdminPanelWithTenant() is declared globally in CustomerResourceTest.php
// and reused here — Pest loads every test file's top-level declarations
// regardless of --filter, so redeclaring it would fatal.

it('shows todays collections and active accounts on the overview widget', function (): void {
    $product = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
    ]);
    $customer = Customer::factory()->forBranch($this->branch)->create();
    $account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $customer->id,
        'savings_product_id' => $product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);

    app(RecordCollectionAction::class)->execute($this->agent, $account, 500);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire(DashboardOverview::class)
        ->assertOk()
        ->assertSee('1 collection(s) recorded')
        ->assertSee(Money::format(500))
        ->assertSee('Active Accounts');
});

it('shows portfolio at risk once a loan installment is overdue', function (): void {
    $loanProduct = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'term_period_count' => 3,
        'grace_period_days' => 0,
        'penalty_rate_bps' => 0,
        'min_amount' => 100_00,
        'max_amount' => 1_000_00,
    ]);
    $customer = Customer::factory()->forBranch($this->branch)->create();

    $loan = app(ApplyForLoanAction::class)->execute($this->agent, $customer, $loanProduct, 300_00);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    $disbursed = app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    $disbursed->installments()->first()->update(['due_date' => now()->subDays(5)->toDateString()]);
    $this->artisan(FlagLoanArrears::class)->assertExitCode(0);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire(DashboardOverview::class)
        ->assertOk()
        ->assertSee('Portfolio At Risk')
        ->assertSee('100%'); // the whole (only) loan is now at risk
});

it('ranks agents by collections total on the leaderboard', function (): void {
    $product = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
    ]);
    $customer = Customer::factory()->forBranch($this->branch)->create();
    $account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $customer->id,
        'savings_product_id' => $product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);

    app(RecordCollectionAction::class)->execute($this->agent, $account, 500);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire(AgentLeaderboard::class)
        ->assertOk()
        ->assertSee($this->agent->name)
        ->assertSee(Money::format(500));
});
