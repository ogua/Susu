<?php

use App\Actions\Collections\BuildCollectionSheetAction;
use App\Actions\Collections\PostCollectionSheetAction;
use App\Actions\GroupLoans\ActivateGroupLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Actions\LoanGroups\BuildLoanGroupHistoryAction;
use App\Actions\LoanGroups\BuildLoanGroupSummaryAction;
use App\Actions\LoanGroups\IssueLoansToGroupAction;
use App\Actions\LoanGroups\OpenSavingsForGroupAction;
use App\Enums\GroupLoanStatus;
use App\Enums\LoanFrequency;
use App\Filament\Pages\CollectionSheet;
use App\Filament\Resources\LoanGroups\Pages\ViewLoanGroup;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->group = LoanGroup::factory()->create(['branch_id' => $this->branch->id]);

    $this->ama = Customer::factory()->forBranch($this->branch)->create(['first_name' => 'Ama']);
    $this->kofi = Customer::factory()->forBranch($this->branch)->create(['first_name' => 'Kofi']);
    app(AddLoanGroupMemberAction::class)->execute($this->group, $this->ama);
    app(AddLoanGroupMemberAction::class)->execute($this->group, $this->kofi);

    $this->product = SavingsProduct::factory()->create(['company_id' => $this->branch->company_id, 'contribution_amount' => 500]);
});

/** Issues 1,000 GHS to every member (no deposit) and activates each, first payment today. */
function issueAndActivateGroupLoans(User $by, LoanGroup $group): void
{
    app(IssueLoansToGroupAction::class)->execute($by, $group, 1000_00, 0, 100_00, LoanFrequency::Weekly, Carbon::today());

    $group->groupLoans()->get()->each(fn (GroupLoan $loan) => app(ActivateGroupLoanAction::class)->execute($loan, $by));
}

it('issues the same loan to every member and skips members with an open loan', function (): void {
    $first = app(IssueLoansToGroupAction::class)->execute($this->manager, $this->group, 500_00, 0, 50_00, LoanFrequency::Weekly, Carbon::today());
    $second = app(IssueLoansToGroupAction::class)->execute($this->manager, $this->group, 500_00, 0, 50_00, LoanFrequency::Weekly, Carbon::today());

    expect($first['issued'])->toHaveCount(2)
        ->and($second['issued'])->toBeEmpty()
        ->and($second['skipped'])->toHaveCount(2);
});

it('opens a daily susu account for each member who lacks one', function (): void {
    SavingsAccount::factory()->create(['branch_id' => $this->branch->id, 'customer_id' => $this->ama->id, 'savings_product_id' => $this->product->id]);

    $result = app(OpenSavingsForGroupAction::class)->execute($this->group, $this->product);

    expect($result['opened'])->toHaveCount(1)
        ->and($result['skipped'])->toHaveCount(1)
        ->and($this->kofi->savingsAccounts()->where('savings_product_id', $this->product->id)->exists())->toBeTrue();
});

it('refuses to open non-susu products for a whole group', function (): void {
    $target = SavingsProduct::factory()->create(['company_id' => $this->branch->company_id, 'type' => 'target']);

    app(OpenSavingsForGroupAction::class)->execute($this->group, $target);
})->throws(ValidationException::class);

it('summarises disbursed, paid and outstanding and builds a history', function (): void {
    issueAndActivateGroupLoans($this->manager, $this->group);
    $loan = $this->group->groupLoans()->first();
    app(RecordGroupLoanRepaymentAction::class)->execute($loan, 100_00, $this->manager);

    $summary = app(BuildLoanGroupSummaryAction::class)->execute($this->group->fresh());
    $history = app(BuildLoanGroupHistoryAction::class)->execute($this->group->fresh());

    expect($summary)
        ->total_disbursed->toBe(2000_00)
        ->total_paid->toBe(100_00)
        ->outstanding->toBe(1900_00)
        ->active_members->toBe(2)
        ->active_loans->toBe(2)
        ->and(collect($history)->pluck('type'))->toContain('group_created', 'member_joined', 'loan_issued', 'loan_activated', 'repayment_recorded');
});

it('builds a group collection sheet with what each member owes today', function (): void {
    issueAndActivateGroupLoans($this->manager, $this->group);
    SavingsAccount::factory()->create(['branch_id' => $this->branch->id, 'customer_id' => $this->ama->id, 'savings_product_id' => $this->product->id]);

    $rows = app(BuildCollectionSheetAction::class)->execute($this->branch, Carbon::today(), $this->group);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['customer_name'])->toContain('Ama')
        ->and($rows[0]['amount_due'])->toBe(100_00)
        ->and($rows[0]['loan_type'])->toBe('group')
        ->and($rows[0]['savings_account_id'])->not->toBeNull()
        ->and($rows[1]['savings_account_id'])->toBeNull();
});

it('posts repayments and deposits from a sheet, all or nothing', function (): void {
    issueAndActivateGroupLoans($this->manager, $this->group);
    $account = SavingsAccount::factory()->create(['branch_id' => $this->branch->id, 'customer_id' => $this->ama->id, 'savings_product_id' => $this->product->id, 'contribution_amount' => 500]);
    [$amaLoan, $kofiLoan] = [$this->ama->groupLoans()->first(), $this->kofi->groupLoans()->first()];

    // A deposit that isn't a multiple of the contribution fails the whole sheet.
    expect(fn () => app(PostCollectionSheetAction::class)->execute($this->manager, [
        ['loan_type' => 'group', 'loan_id' => $kofiLoan->id, 'repayment_amount' => 100_00],
        ['loan_type' => 'group', 'loan_id' => $amaLoan->id, 'repayment_amount' => 100_00, 'savings_account_id' => $account->id, 'deposit_amount' => 333],
    ], Carbon::today()))->toThrow(ValidationException::class, 'Row 2');

    expect($kofiLoan->fresh()->outstanding_balance)->toBe(1000_00);

    $totals = app(PostCollectionSheetAction::class)->execute($this->manager, [
        ['loan_type' => 'group', 'loan_id' => $kofiLoan->id, 'repayment_amount' => 100_00],
        ['loan_type' => 'group', 'loan_id' => $amaLoan->id, 'repayment_amount' => 100_00, 'savings_account_id' => $account->id, 'deposit_amount' => 1000],
    ], Carbon::today());

    expect($totals)->toBe(['repayments_count' => 2, 'repayments_total' => 200_00, 'deposits_count' => 1, 'deposits_total' => 1000])
        ->and($kofiLoan->fresh()->outstanding_balance)->toBe(900_00)
        ->and($account->fresh()->balance)->toBe(1000);
});

it('serves the summary, history, sheet and bulk endpoints over the API', function (): void {
    $this->actingAs($this->manager, 'sanctum')
        ->postJson("/api/v1/loan-groups/{$this->group->id}/open-savings", ['savings_product_id' => $this->product->id])
        ->assertCreated()
        ->assertJsonCount(2, 'data.opened');

    $this->actingAs($this->manager, 'sanctum')
        ->postJson("/api/v1/loan-groups/{$this->group->id}/issue-loans", [
            'principal_amount' => 1000_00,
            'security_deposit_amount' => 0,
            'periodic_amount' => 100_00,
            'repayment_frequency' => 'weekly',
            'start_date' => now()->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonCount(2, 'data.issued');

    $this->group->groupLoans()->get()->each(fn (GroupLoan $loan) => app(ActivateGroupLoanAction::class)->execute($loan, $this->manager));

    $this->actingAs($this->manager, 'sanctum')
        ->getJson("/api/v1/loan-groups/{$this->group->id}")
        ->assertOk()
        ->assertJsonPath('summary.total_disbursed', 2000_00)
        ->assertJsonPath('data.members.0.savings_accounts.0.account_number', fn ($number) => $number !== null);

    $this->actingAs($this->manager, 'sanctum')
        ->getJson("/api/v1/loan-groups/{$this->group->id}/history")
        ->assertOk()
        ->assertJsonFragment(['type' => 'loan_activated']);

    $sheet = $this->actingAs($this->manager, 'sanctum')
        ->getJson("/api/v1/collection-sheet?loan_group_id={$this->group->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->json('data');

    $this->actingAs($this->manager, 'sanctum')
        ->postJson('/api/v1/collection-sheet', [
            'date' => now()->toDateString(),
            'entries' => array_map(fn (array $row): array => [
                'loan_type' => $row['loan_type'],
                'loan_id' => $row['loan_id'],
                'repayment_amount' => $row['amount_due'],
                'repayment_reference' => (string) Str::uuid(),
            ], $sheet),
        ])
        ->assertCreated()
        ->assertJsonPath('data.repayments_total', 200_00);

    expect($this->group->groupLoans()->where('status', GroupLoanStatus::Active)->sum('outstanding_balance'))->toEqual(1800_00);
});

it('serves the collection sheet and loan groups to a company admin without a home branch', function (): void {
    issueAndActivateGroupLoans($this->manager, $this->group);
    $companyAdmin = User::factory()->companyAdmin($this->branch->company)->create(['branch_id' => null]);

    $this->actingAs($companyAdmin, 'sanctum')
        ->getJson('/api/v1/collection-sheet')
        ->assertOk()
        ->assertJsonPath('meta.branch_id', $this->branch->id);

    $this->actingAs($companyAdmin, 'sanctum')
        ->getJson('/api/v1/loan-groups')
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->group->id);

    $this->actingAs($companyAdmin, 'sanctum')
        ->getJson('/api/v1/group-loans')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('renders the group overview and the collection sheet page', function (): void {
    issueAndActivateGroupLoans($this->manager, $this->group);
    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(ViewLoanGroup::class, ['record' => $this->group->getKey()])
        ->assertOk()
        ->assertSee('Total disbursed')
        ->assertSee('GHS 2,000.00', escape: false);

    livewire(CollectionSheet::class)
        ->set('loanGroupId', $this->group->id)
        ->call('loadSheet')
        ->assertSee('Ama')
        ->assertSee('Paid in full')
        ->assertSet('rows.0.repayment', '')
        ->assertSet('rows.1.repayment', '')
        ->call('fillDue', 0)
        ->assertSet('rows.0.repayment', '100.00')
        ->assertSet('rows.1.repayment', '')
        ->call('submit')
        ->assertNotified('Collection sheet posted');

    // Only the row marked paid is posted; Kofi's untouched row posts nothing.
    expect($this->ama->groupLoans()->first()->outstanding_balance)->toBe(900_00)
        ->and($this->kofi->groupLoans()->first()->outstanding_balance)->toBe(1_000_00);
});

it('posts nothing from an untouched collection sheet', function (): void {
    issueAndActivateGroupLoans($this->manager, $this->group);
    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(CollectionSheet::class)
        ->set('loanGroupId', $this->group->id)
        ->call('loadSheet')
        ->call('submit')
        ->assertNotified('Nothing to post — enter at least one amount.');

    expect($this->ama->groupLoans()->first()->outstanding_balance)->toBe(1_000_00)
        ->and($this->kofi->groupLoans()->first()->outstanding_balance)->toBe(1_000_00);
});

it('filters the web sheet by search without dropping entered amounts', function (): void {
    issueAndActivateGroupLoans($this->manager, $this->group);
    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    livewire(CollectionSheet::class)
        ->set('loanGroupId', $this->group->id)
        ->call('loadSheet')
        ->assertSee($this->group->name)
        ->call('fillDue', 0)
        ->set('search', 'Kofi')
        ->assertDontSee('Ama')
        ->assertSee('1 entered row(s) hidden by the search will also be posted.')
        ->call('submit')
        ->assertNotified('Collection sheet posted');

    expect($this->ama->groupLoans()->first()->outstanding_balance)->toBe(900_00);
});

it('loads every customer on the web sheet, not just who is due', function (): void {
    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->branch);
    Filament::bootCurrentPanel();

    // No loans issued: nobody is due, but "all customers" still lists both members.
    livewire(CollectionSheet::class)
        ->call('loadSheet')
        ->assertSet('rows', [])
        ->set('allCustomers', true)
        ->call('loadSheet')
        ->assertCount('rows', 2);
});
