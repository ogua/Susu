<?php

use App\Actions\GroupLoans\ActivateGroupLoanAction;
use App\Actions\GroupLoans\IssueGroupMemberLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanDepositAction;
use App\Enums\GroupLoanStatus;
use App\Enums\LoanFrequency;
use App\Filament\Resources\GroupLoans\Pages\CreateGroupLoan;
use App\Filament\Resources\GroupLoans\Pages\ListGroupLoans;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\SavingsAccount;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();

    $this->loanGroup = LoanGroup::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
    ]);
    $this->customer = Customer::factory()->forBranch($this->branch)->create();
});

function bootAdmin(User $user, Branch $branch): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($branch);
    Filament::bootCurrentPanel();
}

function draftLoan(User $agent, LoanGroup $group, Customer $customer): GroupLoan
{
    return app(IssueGroupMemberLoanAction::class)->execute(
        issuedBy: $agent,
        loanGroup: $group,
        customer: $customer,
        principal: 1000_00,
        securityDeposit: 100_00,
        periodicAmount: 100_00,
        frequency: LoanFrequency::Weekly,
        startDate: Carbon::now(),
    );
}

it('issues a member loan via the Filament create page routed through the action', function (): void {
    bootAdmin($this->manager, $this->branch);

    livewire(CreateGroupLoan::class)
        ->fillForm([
            'loan_group_id' => $this->loanGroup->id,
            'customer_id' => $this->customer->id,
            'principal_amount' => '1000.00',
            'security_deposit_amount' => '100.00',
            'periodic_amount' => '100.00',
            'repayment_frequency' => 'weekly',
            'start_date' => Carbon::now()->toDateString(),
        ])
        ->call('create')
        ->assertNotified();

    $loan = GroupLoan::where('loan_group_id', $this->loanGroup->id)->firstOrFail();
    expect($loan->principal_amount)->toBe(1000_00)
        ->and($loan->periodic_amount)->toBe(100_00)
        ->and($loan->total_periods)->toBe(10)
        ->and($loan->status)->toBe(GroupLoanStatus::Draft);
});

it('renders the group loans list scoped to the tenant branch', function (): void {
    $own = draftLoan($this->agent, $this->loanGroup->fresh(), $this->customer);

    $otherBranch = Branch::factory()->create(['company_id' => $this->branch->company_id]);
    $otherGroup = LoanGroup::factory()->create(['company_id' => $otherBranch->company_id, 'branch_id' => $otherBranch->id]);
    $other = draftLoan(
        User::factory()->fieldAgent($otherBranch)->create(),
        $otherGroup->fresh(),
        Customer::factory()->forBranch($otherBranch)->create(),
    );

    bootAdmin($this->manager, $this->branch);

    livewire(ListGroupLoans::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$other]);
});

it('walks deposit -> activate -> repayment from the table row actions', function (): void {
    $loan = draftLoan($this->agent, $this->loanGroup->fresh(), $this->customer);
    $savingsAccount = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
    ]);
    bootAdmin($this->manager, $this->branch);

    livewire(ListGroupLoans::class)
        ->callAction(TestAction::make('recordDeposit')->table($loan), data: ['savings_account_id' => $savingsAccount->id, 'amount' => '100.00'])
        ->assertHasNoActionErrors();

    expect($savingsAccount->fresh()->balance)->toBe(100_00);

    expect($loan->fresh()->deposit_status->value)->toBe('held');

    livewire(ListGroupLoans::class)
        ->callAction(TestAction::make('activate')->table($loan->fresh()))
        ->assertHasNoActionErrors();

    expect($loan->fresh()->status)->toBe(GroupLoanStatus::Active);

    livewire(ListGroupLoans::class)
        ->callAction(TestAction::make('recordRepayment')->table($loan->fresh()), data: ['amount' => '250.00'])
        ->assertHasNoActionErrors();

    expect($loan->fresh()->outstanding_balance)->toBe(750_00);
});

it('denies a field agent the write-off action but allows a branch manager', function (): void {
    $loan = draftLoan($this->agent, $this->loanGroup->fresh(), $this->customer);
    $savingsAccount = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
    ]);
    app(RecordGroupLoanDepositAction::class)->execute($loan, $savingsAccount, 100_00, $this->agent);
    app(ActivateGroupLoanAction::class)->execute($loan->fresh(), $this->agent);

    bootAdmin($this->agent, $this->branch);
    livewire(ListGroupLoans::class)
        ->assertActionHidden(TestAction::make('writeOff')->table($loan->fresh()));

    bootAdmin($this->manager, $this->branch);
    livewire(ListGroupLoans::class)
        ->callAction(TestAction::make('writeOff')->table($loan->fresh()), data: ['reason' => 'Uncollectible'])
        ->assertHasNoActionErrors();

    expect($loan->fresh()->status)->toBe(GroupLoanStatus::WrittenOff);
});
