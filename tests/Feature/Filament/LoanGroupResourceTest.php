<?php

use App\Filament\Resources\LoanGroups\Pages\CreateLoanGroup;
use App\Filament\Resources\LoanGroups\Pages\ListLoanGroups;
use App\Filament\Resources\LoanGroups\Pages\ViewLoanGroup;
use App\Filament\Resources\LoanGroups\RelationManagers\MembersRelationManager;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
});

it('lets a branch manager create a loan group via the Filament create page', function (): void {
    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(CreateLoanGroup::class)
        ->fillForm([
            'name' => 'Market Traders Loan Group',
            'code' => 'MTLG-001',
        ])
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

    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ListLoanGroups::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$ownGroup])
        ->assertCanNotSeeTableRecords([$otherGroup]);
});

it('denies field agents from creating a loan group', function (): void {
    $this->actingAs($this->agent);

    expect($this->agent->can('create', LoanGroup::class))->toBeFalse();
});

it('lets a branch manager add a member to a loan group from the view page relation manager', function (): void {
    $loanGroup = LoanGroup::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);
    $customer = Customer::factory()->forBranch($this->branch)->create();

    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(MembersRelationManager::class, [
        'ownerRecord' => $loanGroup,
        'pageClass' => ViewLoanGroup::class,
    ])
        ->callTableAction('addMember', data: ['customer_id' => $customer->id])
        ->assertHasNoTableActionErrors();

    expect(LoanGroupMember::where('loan_group_id', $loanGroup->id)->where('customer_id', $customer->id)->where('status', 'active')->exists())
        ->toBeTrue();
});

it('denies a field agent from adding a loan group member (no update ability)', function (): void {
    $loanGroup = LoanGroup::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);

    $this->actingAs($this->agent);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(MembersRelationManager::class, [
        'ownerRecord' => $loanGroup,
        'pageClass' => ViewLoanGroup::class,
    ])->assertTableActionHidden('addMember');
});
