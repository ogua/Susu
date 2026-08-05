<?php

use App\Filament\Resources\AgentDailySummaries\Pages\ListAgentDailySummaries;
use App\Filament\Resources\GroupLoans\Pages\ListGroupLoans;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Resources\LoanGroups\Pages\ListLoanGroups;
use App\Filament\Resources\Loans\Pages\ListLoans;
use App\Filament\Resources\PaymentIntents\Pages\ListPaymentIntents;
use App\Filament\Resources\SavingsAccounts\Pages\ListSavingsAccounts;
use App\Filament\Resources\WithdrawalRequests\Pages\ListWithdrawalRequests;
use App\Models\AgentDailySummary;
use App\Models\Branch;
use App\Models\Group;
use App\Models\GroupLoan;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanGroup;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Filament\Facades\Filament;

beforeEach(function (): void {
    seedRoles();

    $this->branchA = Branch::factory()->create();
    $this->branchB = Branch::factory()->create(['company_id' => $this->branchA->company_id]);

    $this->owner = User::factory()->companyAdmin()->create(['company_id' => $this->branchA->company_id]);
    $this->owner->branches()->syncWithoutDetaching([$this->branchA->id, $this->branchB->id]);

    $this->manager = User::factory()->branchManager($this->branchA)->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branchA);
    Filament::bootCurrentPanel();
});

it('lets the company owner see withdrawal requests from a branch other than the current tenant', function (): void {
    $account = SavingsAccount::factory()->create([
        'company_id' => $this->branchB->company_id,
        'branch_id' => $this->branchB->id,
    ]);
    $request = WithdrawalRequest::factory()->create([
        'company_id' => $this->branchB->company_id,
        'branch_id' => $this->branchB->id,
        'savings_account_id' => $account->id,
        'customer_id' => $account->customer_id,
    ]);

    livewire(ListWithdrawalRequests::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$request]);
});

it('keeps a branch manager scoped to their own branch for withdrawal requests', function (): void {
    $account = SavingsAccount::factory()->create([
        'company_id' => $this->branchB->company_id,
        'branch_id' => $this->branchB->id,
    ]);
    $otherBranchRequest = WithdrawalRequest::factory()->create([
        'company_id' => $this->branchB->company_id,
        'branch_id' => $this->branchB->id,
        'savings_account_id' => $account->id,
        'customer_id' => $account->customer_id,
    ]);

    $this->actingAs($this->manager);
    Filament::setTenant($this->branchA);

    livewire(ListWithdrawalRequests::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$otherBranchRequest]);
});

it('lets the company owner see loans from a branch other than the current tenant', function (): void {
    $loan = Loan::factory()->create(['company_id' => $this->branchB->company_id, 'branch_id' => $this->branchB->id]);

    livewire(ListLoans::class)->assertOk()->assertCanSeeTableRecords([$loan]);
});

it('lets the company owner see savings accounts from a branch other than the current tenant', function (): void {
    $account = SavingsAccount::factory()->create(['company_id' => $this->branchB->company_id, 'branch_id' => $this->branchB->id]);

    livewire(ListSavingsAccounts::class)->assertOk()->assertCanSeeTableRecords([$account]);
});

it('lets the company owner see groups from a branch other than the current tenant', function (): void {
    $group = Group::factory()->create(['company_id' => $this->branchB->company_id, 'branch_id' => $this->branchB->id]);

    livewire(ListGroups::class)->assertOk()->assertCanSeeTableRecords([$group]);
});

it('lets the company owner see group loans from a branch other than the current tenant', function (): void {
    $groupLoan = GroupLoan::factory()->create(['company_id' => $this->branchB->company_id, 'branch_id' => $this->branchB->id]);

    livewire(ListGroupLoans::class)->assertOk()->assertCanSeeTableRecords([$groupLoan]);
});

it('lets the company owner see loan groups from a branch other than the current tenant', function (): void {
    $loanGroup = LoanGroup::factory()->create(['company_id' => $this->branchB->company_id, 'branch_id' => $this->branchB->id]);

    livewire(ListLoanGroups::class)->assertOk()->assertCanSeeTableRecords([$loanGroup]);
});

it('lets the company owner see agent day sheets from a branch other than the current tenant', function (): void {
    $summary = AgentDailySummary::factory()->create(['company_id' => $this->branchB->company_id, 'branch_id' => $this->branchB->id]);

    livewire(ListAgentDailySummaries::class)->assertOk()->assertCanSeeTableRecords([$summary]);
});

it('lets the company owner see journal entries from a branch other than the current tenant', function (): void {
    $entry = JournalEntry::factory()->create(['company_id' => $this->branchB->company_id, 'branch_id' => $this->branchB->id]);

    livewire(ListJournalEntries::class)->assertOk()->assertCanSeeTableRecords([$entry]);
});

it('lets the company owner see payment intents from a branch other than the current tenant', function (): void {
    $intent = PaymentIntent::factory()->create(['company_id' => $this->branchB->company_id, 'branch_id' => $this->branchB->id]);

    livewire(ListPaymentIntents::class)->assertOk()->assertCanSeeTableRecords([$intent]);
});
