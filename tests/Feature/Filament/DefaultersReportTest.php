<?php

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Console\Commands\FlagLoanArrears;
use App\Filament\Pages\DefaultersReport;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoanProduct;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
});

it('lists overdue installments for the tenant branch with days overdue and amount due', function (): void {
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

    $installment = $disbursed->installments()->first();
    $installment->update(['due_date' => now()->subDays(7)->toDateString()]);

    $this->artisan(FlagLoanArrears::class)->assertExitCode(0);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    livewire(DefaultersReport::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$installment->fresh()])
        ->assertSee($customer->fullName())
        ->assertSee($loan->loan_number)
        ->assertSee('7'); // days overdue
});

it('excludes installments from other branches', function (): void {
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
    $disbursed->installments()->first()->update(['due_date' => now()->subDays(3)->toDateString()]);
    $this->artisan(FlagLoanArrears::class)->assertExitCode(0);

    $otherBranch = Branch::factory()->create();
    $otherManager = User::factory()->branchManager($otherBranch)->create();

    $this->actingAs($otherManager);
    bootAdminPanelWithTenant($otherBranch);

    livewire(DefaultersReport::class)
        ->assertOk()
        ->assertCanSeeTableRecords([]);
});

it('denies customers access to the defaulters report', function (): void {
    $customerUser = User::factory()->customerUser()->create(['company_id' => $this->branch->company_id]);
    $this->actingAs($customerUser);

    expect(DefaultersReport::canAccess())->toBeFalse();
});
