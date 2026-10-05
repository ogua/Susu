<?php

use App\Enums\GroupLoanStatus;
use App\Filament\Resources\LoanGroups\Pages\CreateLoanGroup;
use App\Filament\Resources\LoanGroups\Pages\ListLoanGroups;
use App\Filament\Resources\LoanGroups\Pages\ViewLoanGroup;
use App\Filament\Resources\LoanGroups\RelationManagers\MembersRelationManager;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use App\Models\SavingsAccount;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
});

function bootAdminPanel(User $user, Branch $branch): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($branch);
    Filament::bootCurrentPanel();
}

it('lets a branch manager create a loan group via the Filament create page', function (): void {
    bootAdminPanel($this->manager, $this->branch);

    livewire(CreateLoanGroup::class)
        ->fillForm(['name' => 'Market Traders Loan Group', 'code' => 'MTLG-001'])
        ->call('create')
        ->assertNotified();

    $loanGroup = LoanGroup::where('code', 'MTLG-001')->firstOrFail();
    expect($loanGroup->is_active)->toBeTrue()
        ->and($loanGroup->branch_id)->toBe($this->branch->id);
});

it('renders the loan groups list scoped to the tenant branch', function (): void {
    $ownGroup = LoanGroup::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);
    $otherBranch = Branch::factory()->create(['company_id' => $this->branch->company_id]);
    $otherGroup = LoanGroup::factory()->create(['company_id' => $otherBranch->company_id, 'branch_id' => $otherBranch->id]);

    bootAdminPanel($this->manager, $this->branch);

    livewire(ListLoanGroups::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$ownGroup])
        ->assertCanNotSeeTableRecords([$otherGroup]);
});

it('denies field agents from creating a loan group', function (): void {
    expect($this->agent->can('create', LoanGroup::class))->toBeFalse();
});

it('adds a member then issues a loan from the members relation manager', function (): void {
    $loanGroup = LoanGroup::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);
    $customer = Customer::factory()->forBranch($this->branch)->create();

    bootAdminPanel($this->manager, $this->branch);

    livewire(MembersRelationManager::class, ['ownerRecord' => $loanGroup, 'pageClass' => ViewLoanGroup::class])
        ->callTableAction('addMember', data: ['customer_id' => $customer->id])
        ->assertHasNoTableActionErrors();

    $member = LoanGroupMember::where('loan_group_id', $loanGroup->id)->where('customer_id', $customer->id)->firstOrFail();

    livewire(MembersRelationManager::class, ['ownerRecord' => $loanGroup, 'pageClass' => ViewLoanGroup::class])
        ->callTableAction('issueLoan', record: $member, data: [
            'principal_amount' => '1000.00',
            'security_deposit_amount' => '100.00',
            'periodic_amount' => '100.00',
            'repayment_frequency' => 'weekly',
            'start_date' => Carbon::now()->toDateString(),
        ])
        ->assertHasNoTableActionErrors();

    $loan = GroupLoan::where('loan_group_member_id', $member->id)->firstOrFail();
    expect($loan->status)->toBe(GroupLoanStatus::Draft)
        ->and($loan->principal_amount)->toBe(1000_00)
        ->and($loan->total_periods)->toBe(10);
});

it('replaces Issue Loan with the next step once a member has a draft loan', function (): void {
    $loanGroup = LoanGroup::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);
    $customer = Customer::factory()->forBranch($this->branch)->create();
    $savingsAccount = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $customer->id,
    ]);
    $member = LoanGroupMember::factory()->create(['loan_group_id' => $loanGroup->id, 'customer_id' => $customer->id]);

    bootAdminPanel($this->manager, $this->branch);

    $members = fn () => livewire(MembersRelationManager::class, ['ownerRecord' => $loanGroup, 'pageClass' => ViewLoanGroup::class]);

    $members()
        ->assertTableActionVisible('issueLoan', $member)
        ->assertTableActionHidden('recordDeposit', $member)
        ->callTableAction('issueLoan', record: $member, data: [
            'principal_amount' => '1000.00',
            'security_deposit_amount' => '100.00',
            'periodic_amount' => '100.00',
            'repayment_frequency' => 'weekly',
            'start_date' => Carbon::now()->toDateString(),
        ])
        ->assertHasNoTableActionErrors()
        ->assertTableActionHidden('issueLoan', $member)
        ->assertTableActionVisible('recordDeposit', $member)
        ->assertTableActionHidden('activate', $member);

    $members()
        ->callTableAction('recordDeposit', record: $member, data: ['savings_account_id' => $savingsAccount->id])
        ->assertHasNoTableActionErrors()
        ->assertTableActionHidden('issueLoan', $member)
        ->assertTableActionHidden('recordDeposit', $member)
        ->assertTableActionVisible('activate', $member);

    $members()
        ->callTableAction('activate', record: $member)
        ->assertHasNoTableActionErrors()
        ->assertTableActionHidden('issueLoan', $member)
        ->assertTableActionHidden('activate', $member);

    expect(GroupLoan::where('loan_group_member_id', $member->id)->sole()->status)->toBe(GroupLoanStatus::Active);
});

it('denies a field agent from adding a loan group member (no update ability)', function (): void {
    $loanGroup = LoanGroup::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);

    bootAdminPanel($this->agent, $this->branch);

    livewire(MembersRelationManager::class, ['ownerRecord' => $loanGroup, 'pageClass' => ViewLoanGroup::class])
        ->assertTableActionHidden('addMember');
});
