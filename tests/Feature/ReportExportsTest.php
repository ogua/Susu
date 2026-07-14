<?php

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Console\Commands\FlagLoanArrears;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoanProduct;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->companyAdmin = User::factory()->companyAdmin($this->branch->company)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
});

it('lets a company admin download the trial balance as PDF and Excel', function (): void {
    $this->actingAs($this->companyAdmin)
        ->get(route('reports.trial-balance.pdf', $this->branch))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->companyAdmin)
        ->get(route('reports.trial-balance.excel', $this->branch))
        ->assertOk();
});

it('denies a field agent the trial balance export', function (): void {
    $this->actingAs($this->agent)
        ->get(route('reports.trial-balance.pdf', $this->branch))
        ->assertForbidden();
});

it('lets a field agent download the defaulters report for their own branch', function (): void {
    $loanProduct = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
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

    $this->actingAs($this->agent)
        ->get(route('reports.defaulters.pdf', $this->branch))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->agent)
        ->get(route('reports.defaulters.excel', $this->branch))
        ->assertOk();
});

it('denies a manager from another branch the defaulters report', function (): void {
    $otherBranch = Branch::factory()->create();
    $otherManager = User::factory()->branchManager($otherBranch)->create();

    $this->actingAs($otherManager)
        ->get(route('reports.defaulters.pdf', $this->branch))
        ->assertNotFound();
});

it('lets a branch manager download the cash position report', function (): void {
    $this->actingAs($this->manager)
        ->get(route('reports.cash-position.pdf', $this->branch))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->manager)
        ->get(route('reports.cash-position.excel', $this->branch))
        ->assertOk();
});

it('denies a field agent the cash position report', function (): void {
    $this->actingAs($this->agent)
        ->get(route('reports.cash-position.pdf', $this->branch))
        ->assertForbidden();
});
