<?php

use App\Actions\GroupLoans\ApplyForGroupLoanAction;
use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Enums\GroupLoanStatus;
use App\Filament\Resources\GroupLoans\Pages\CreateGroupLoan;
use App\Filament\Resources\GroupLoans\Pages\ListGroupLoans;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\LoanProduct;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();

    $this->loanGroup = LoanGroup::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
    ]);
    $this->loanProduct = LoanProduct::factory()->create(['company_id' => $this->branch->company_id]);

    $this->members = collect(range(1, 2))->map(function () {
        $customer = Customer::factory()->forBranch($this->branch)->create();

        return app(AddLoanGroupMemberAction::class)->execute($this->loanGroup, $customer);
    });
});

it('lets a branch manager apply for a group loan via the Filament create page', function (): void {
    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(CreateGroupLoan::class)
        ->fillForm([
            'loan_group_id' => $this->loanGroup->id,
            'loan_product_id' => $this->loanProduct->id,
            'amount' => '1000.00',
        ])
        ->call('create')
        ->assertNotified();

    $groupLoan = GroupLoan::where('loan_group_id', $this->loanGroup->id)->firstOrFail();
    expect($groupLoan->principal_amount)->toBe(1000_00)
        ->and($groupLoan->status)->toBe(GroupLoanStatus::Applied);
});

it('renders the group loans list scoped to the tenant branch', function (): void {
    $ownLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->loanProduct, 1000_00);

    $otherBranch = Branch::factory()->create(['company_id' => $this->branch->company_id]);
    $otherGroup = LoanGroup::factory()->create(['company_id' => $otherBranch->company_id, 'branch_id' => $otherBranch->id]);
    $otherCustomers = collect(range(1, 2))->map(fn () => Customer::factory()->forBranch($otherBranch)->create());
    $otherCustomers->each(fn ($customer) => app(AddLoanGroupMemberAction::class)->execute($otherGroup, $customer));
    $otherProduct = LoanProduct::factory()->create(['company_id' => $otherBranch->company_id]);
    $otherLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $otherGroup->fresh(), $otherProduct, 1000_00);

    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListGroupLoans::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$ownLoan])
        ->assertCanNotSeeTableRecords([$otherLoan]);
});

it('lets a branch manager approve then disburse a group loan from the table actions', function (): void {
    $groupLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->loanProduct, 1000_00);

    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListGroupLoans::class)
        ->assertOk()
        ->callAction(TestAction::make('approve')->table($groupLoan))
        ->assertNotified();

    expect($groupLoan->refresh()->status)->toBe(GroupLoanStatus::Approved);

    livewire(ListGroupLoans::class)
        ->callAction(TestAction::make('disburse')->table($groupLoan))
        ->assertNotified();

    expect($groupLoan->refresh()->status)->toBe(GroupLoanStatus::Disbursed)
        ->and($groupLoan->installments)->toHaveCount($this->loanProduct->term_period_count)
        ->and($groupLoan->borrowers)->toHaveCount(2);
});

it('denies field agents from approving a group loan', function (): void {
    $groupLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->loanProduct, 1000_00);

    $this->actingAs($this->agent);
    expect($this->agent->can('approve', $groupLoan))->toBeFalse();
});

it('lets a branch manager write off a disbursed group loan from the table action', function (): void {
    $groupLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->loanProduct, 1000_00);

    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListGroupLoans::class)
        ->callAction(TestAction::make('approve')->table($groupLoan))
        ->assertNotified();

    livewire(ListGroupLoans::class)
        ->callAction(TestAction::make('disburse')->table($groupLoan->fresh()))
        ->assertNotified();

    livewire(ListGroupLoans::class)
        ->callAction(TestAction::make('writeOff')->table($groupLoan->fresh()), data: ['reason' => 'Group disbanded'])
        ->assertNotified();

    expect($groupLoan->refresh()->status)->toBe(GroupLoanStatus::WrittenOff)
        ->and($groupLoan->write_off_reason)->toBe('Group disbanded');
});

it('denies field agents from writing off a group loan', function (): void {
    $groupLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->loanProduct, 1000_00);

    $this->actingAs($this->agent);
    expect($this->agent->can('writeOff', $groupLoan))->toBeFalse();
});
