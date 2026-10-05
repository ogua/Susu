<?php

use App\Actions\GroupLoans\ActivateGroupLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Actions\LoanGroups\IssueLoansToGroupAction;
use App\Enums\LoanFrequency;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
 * A field agent only sees and collects for their own customers (AgentAssignment):
 * Ama is assigned to the agent, Kofi to nobody. Both are in the same group.
 */
beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->group = LoanGroup::factory()->create(['branch_id' => $this->branch->id]);

    $this->ama = Customer::factory()->forBranch($this->branch)->create(['first_name' => 'Ama', 'assigned_agent_id' => $this->agent->id]);
    $this->kofi = Customer::factory()->forBranch($this->branch)->create(['first_name' => 'Kofi']);
    app(AddLoanGroupMemberAction::class)->execute($this->group, $this->ama);
    app(AddLoanGroupMemberAction::class)->execute($this->group, $this->kofi);

    // Issued by the manager, so the loans carry no agent: ownership comes from the customer.
    app(IssueLoansToGroupAction::class)->execute($this->manager, $this->group, 1000_00, 0, 100_00, LoanFrequency::Weekly, Carbon::today());
    $this->group->groupLoans()->get()->each(fn (GroupLoan $loan) => app(ActivateGroupLoanAction::class)->execute($loan, $this->manager));

    $product = SavingsProduct::factory()->create(['company_id' => $this->branch->company_id, 'contribution_amount' => 500]);
    foreach ([$this->ama, $this->kofi] as $customer) {
        SavingsAccount::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'savings_product_id' => $product->id,
            'agent_id' => $customer->is($this->ama) ? $this->agent->id : $this->manager->id,
        ]);
    }
});

it('shows a field agent only their own members on a group sheet', function (): void {
    $rows = $this->actingAs($this->agent, 'sanctum')
        ->getJson("/api/v1/collection-sheet?loan_group_id={$this->group->id}")
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['customer_id'])->toBe($this->ama->id)
        ->and($rows[0]['loan_id'])->not->toBeNull()
        ->and($rows[0]['savings_account_id'])->not->toBeNull();
});

it('hides savings accounts another agent collects', function (): void {
    $this->ama->savingsAccounts()->update(['agent_id' => $this->manager->id]);

    $rows = $this->actingAs($this->agent, 'sanctum')
        ->getJson("/api/v1/collection-sheet?loan_group_id={$this->group->id}")
        ->json('data');

    expect($rows[0]['savings_account_id'])->toBeNull();
});

it('still shows a manager every member on a group sheet', function (): void {
    $this->actingAs($this->manager, 'sanctum')
        ->getJson("/api/v1/collection-sheet?loan_group_id={$this->group->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('lets a field agent repay only loans they are assigned to', function (): void {
    $amaLoan = $this->ama->groupLoans()->first();
    $kofiLoan = $this->kofi->groupLoans()->first();

    app(RecordGroupLoanRepaymentAction::class)->execute($amaLoan, 100_00, $this->agent);
    expect($amaLoan->fresh()->outstanding_balance)->toBe(900_00);

    expect(fn () => app(RecordGroupLoanRepaymentAction::class)->execute($kofiLoan, 100_00, $this->agent))
        ->toThrow(ValidationException::class, 'You are not assigned to this loan.');
    expect($kofiLoan->fresh()->outstanding_balance)->toBe(1000_00);

    // Managers are never restricted.
    app(RecordGroupLoanRepaymentAction::class)->execute($kofiLoan, 100_00, $this->manager);
    expect($kofiLoan->fresh()->outstanding_balance)->toBe(900_00);
});

it('lists only the groups and group loans a field agent works with', function (): void {
    $kofiOnly = LoanGroup::factory()->create(['branch_id' => $this->branch->id]);
    app(AddLoanGroupMemberAction::class)->execute($kofiOnly, Customer::factory()->forBranch($this->branch)->create());
    $ownEmpty = LoanGroup::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->agent->id]);

    $groupIds = collect($this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/loan-groups')->assertOk()->json('data'))->pluck('id');
    expect($groupIds->all())->toEqualCanonicalizing([$this->group->id, $ownEmpty->id]);

    $this->getJson("/api/v1/loan-groups/{$kofiOnly->id}")->assertNotFound();

    $loanCustomers = collect($this->getJson('/api/v1/group-loans')->assertOk()->json('data'))->pluck('customer_id');
    expect($loanCustomers->all())->toBe([$this->ama->id]);

    $this->getJson('/api/v1/group-loans/'.$this->kofi->groupLoans()->first()->id)->assertNotFound();
});
