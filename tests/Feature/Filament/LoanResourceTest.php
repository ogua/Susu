<?php

use App\Actions\Loans\ApplyForLoanAction;
use App\Enums\LoanStatus;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Loans\Pages\CreateLoan;
use App\Filament\Resources\Loans\Pages\ListLoans;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->customer = Customer::factory()->forBranch($this->branch)->create();
    $this->loanProduct = LoanProduct::factory()->create(['company_id' => $this->branch->company_id]);
});

it('lets a branch manager apply for a loan via the Filament create page', function (): void {
    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(CreateLoan::class)
        ->fillForm([
            'customer_id' => $this->customer->id,
            'loan_product_id' => $this->loanProduct->id,
            'amount' => '300.00',
        ])
        ->call('create')
        ->assertNotified();

    $loan = Loan::where('customer_id', $this->customer->id)->firstOrFail();
    expect($loan->principal_amount)->toBe(300_00)
        ->and($loan->status)->toBe(LoanStatus::Applied);
});

it('renders the loans list scoped to the tenant branch', function (): void {
    $ownLoan = app(ApplyForLoanAction::class)->execute($this->agent, $this->customer, $this->loanProduct, 300_00);

    $otherBranch = Branch::factory()->create(['company_id' => $this->branch->company_id]);
    $otherCustomer = Customer::factory()->forBranch($otherBranch)->create();
    $otherProduct = LoanProduct::factory()->create(['company_id' => $otherBranch->company_id]);
    $otherLoan = app(ApplyForLoanAction::class)->execute($this->agent, $otherCustomer, $otherProduct, 300_00);

    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListLoans::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$ownLoan])
        ->assertCanNotSeeTableRecords([$otherLoan]);
});

it('lets a branch manager approve then disburse a loan from the table actions', function (): void {
    $loan = app(ApplyForLoanAction::class)->execute($this->agent, $this->customer, $this->loanProduct, 300_00);

    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListLoans::class)
        ->assertOk()
        ->callAction(TestAction::make('approve')->table($loan))
        ->assertNotified();

    expect($loan->refresh()->status)->toBe(LoanStatus::Approved);

    livewire(ListLoans::class)
        ->callAction(TestAction::make('disburse')->table($loan))
        ->assertNotified();

    expect($loan->refresh()->status)->toBe(LoanStatus::Disbursed)
        ->and($loan->installments)->toHaveCount($this->loanProduct->term_period_count);
});

it('denies field agents from approving a loan', function (): void {
    $loan = app(ApplyForLoanAction::class)->execute($this->agent, $this->customer, $this->loanProduct, 300_00);

    $this->actingAs($this->agent);
    expect(LoanResource::canViewAny())->toBeTrue() // agents can view/apply
        ->and($this->agent->can('approve', $loan))->toBeFalse();
});
