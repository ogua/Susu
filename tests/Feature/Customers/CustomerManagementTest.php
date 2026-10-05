<?php

use App\Actions\Customers\AssignCustomerAgentAction;
use App\Actions\Customers\BuildCustomerOverviewAction;
use App\Actions\Customers\TransferCustomerAction;
use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Enums\CustomerSegment;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\LoanGroup;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    seedRoles();

    $this->from = Branch::factory()->create();
    $this->to = Branch::factory()->create(['company_id' => $this->from->company_id]);
    $this->admin = User::factory()->companyAdmin($this->from->company)->create();
    $this->manager = User::factory()->branchManager($this->from)->create();
    $this->fromAgent = User::factory()->fieldAgent($this->from)->create();
    $this->toAgent = User::factory()->fieldAgent($this->to)->create();

    $this->customer = Customer::factory()->individual()->forBranch($this->from)->create(['assigned_agent_id' => $this->fromAgent->id]);
    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->from->id,
        'customer_id' => $this->customer->id,
        'agent_id' => $this->fromAgent->id,
        'balance' => 12_000,
    ]);
});

it('moves the customer with their accounts, ledger sub-accounts, open loans and pending withdrawals', function (): void {
    $loan = Loan::factory()->create([
        'company_id' => $this->from->company_id,
        'branch_id' => $this->from->id,
        'customer_id' => $this->customer->id,
        'status' => 'applied',
    ]);
    $withdrawal = WithdrawalRequest::factory()->create([
        'company_id' => $this->from->company_id,
        'branch_id' => $this->from->id,
        'customer_id' => $this->customer->id,
        'savings_account_id' => $this->account->id,
        'status' => 'pending',
    ]);

    app(TransferCustomerAction::class)->execute($this->customer, $this->to, $this->admin, $this->toAgent, 'Relocated');

    expect($this->customer->fresh())
        ->branch_id->toBe($this->to->id)
        ->assigned_agent_id->toBe($this->toAgent->id)
        ->and($this->account->fresh())
        ->branch_id->toBe($this->to->id)
        ->agent_id->toBe($this->toAgent->id)
        ->and(LedgerAccount::find($this->account->ledger_account_id)->branch_id)->toBe($this->to->id)
        ->and($loan->fresh()->branch_id)->toBe($this->to->id)
        ->and($withdrawal->fresh()->branch_id)->toBe($this->to->id)
        ->and(Activity::where('subject_id', $this->customer->id)->where('event', 'transferred')->exists())->toBeTrue();
});

it('clears the old agent when no new agent is given', function (): void {
    app(TransferCustomerAction::class)->execute($this->customer, $this->to, $this->admin);

    expect($this->customer->fresh()->assigned_agent_id)->toBeNull()
        ->and($this->account->fresh()->agent_id)->toBeNull();
});

it('refuses to transfer a customer still on a customer group', function (): void {
    $group = LoanGroup::factory()->create(['branch_id' => $this->from->id]);
    app(AddLoanGroupMemberAction::class)->execute($group, $this->customer);

    app(TransferCustomerAction::class)->execute($this->customer, $this->to, $this->admin);
})->throws(ValidationException::class, 'customer group');

it('refuses a transfer to the same branch or another company', function (): void {
    expect(fn () => app(TransferCustomerAction::class)->execute($this->customer, $this->from, $this->admin))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(TransferCustomerAction::class)->execute($this->customer, Branch::factory()->create(), $this->admin))
        ->toThrow(ValidationException::class);
});

it('bulk transfers independently, reporting the customers it skipped', function (): void {
    $blocked = Customer::factory()->forBranch($this->from)->create();
    app(AddLoanGroupMemberAction::class)->execute(LoanGroup::factory()->create(['branch_id' => $this->from->id]), $blocked);

    $result = app(TransferCustomerAction::class)->executeMany(collect([$this->customer, $blocked]), $this->to, $this->admin);

    expect($result['transferred'])->toBe([$this->customer->id])
        ->and($result['failed'])->toHaveKey($blocked->id);
});

it('assigns an agent to the customer and their active accounts', function (): void {
    $newAgent = User::factory()->fieldAgent($this->from)->create();

    app(AssignCustomerAgentAction::class)->execute($this->customer, $newAgent, $this->manager);

    expect($this->customer->fresh()->assigned_agent_id)->toBe($newAgent->id)
        ->and($this->account->fresh()->agent_id)->toBe($newAgent->id);
});

it('rejects an agent from another branch', function (): void {
    app(AssignCustomerAgentAction::class)->execute($this->customer, $this->toAgent, $this->manager);
})->throws(ValidationException::class);

it('counts customers per segment', function (): void {
    Customer::factory()->forBranch($this->from)->create(); // no account yet → pending
    WithdrawalRequest::factory()->create([
        'company_id' => $this->from->company_id,
        'branch_id' => $this->from->id,
        'customer_id' => $this->customer->id,
        'savings_account_id' => $this->account->id,
        'status' => 'pending',
    ]);

    $overview = app(BuildCustomerOverviewAction::class)->execute(Customer::where('branch_id', $this->from->id));

    expect($overview['total'])->toBe(2)
        ->and($overview['segments'][CustomerSegment::Pending->value])->toBe(1)
        ->and($overview['segments'][CustomerSegment::WithdrawalRequests->value])->toBe(1)
        ->and($overview['savings_balance'])->toBe(12_000);
});

it('lists customers by segment over the API', function (): void {
    Customer::factory()->forBranch($this->from)->create();

    $this->actingAs($this->manager, 'sanctum')
        ->getJson('/api/v1/customers?segment=pending')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $all = $this->actingAs($this->manager, 'sanctum')
        ->getJson('/api/v1/customers')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->json('data');

    expect(collect($all)->firstWhere('id', $this->customer->id)['savings_balance'])->toBe(12_000);

    $this->actingAs($this->manager, 'sanctum')
        ->getJson('/api/v1/customers/overview')
        ->assertOk()
        ->assertJsonPath('data.total', 2);
});

it('transfers and reassigns over the API, and forbids field agents', function (): void {
    $this->actingAs($this->fromAgent, 'sanctum')
        ->postJson("/api/v1/customers/{$this->customer->id}/transfer", ['branch_id' => $this->to->id])
        ->assertForbidden();

    $sameBranchAgent = User::factory()->fieldAgent($this->from)->create();
    $this->actingAs($this->manager, 'sanctum')
        ->postJson("/api/v1/customers/{$this->customer->id}/assign-agent", ['agent_id' => $sameBranchAgent->id])
        ->assertOk()
        ->assertJsonPath('data.assigned_agent_id', $sameBranchAgent->id);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/customers/transfer', [
            'branch_id' => $this->to->id,
            'agent_id' => $this->toAgent->id,
            'customer_ids' => [$this->customer->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.transferred.0', $this->customer->id);

    expect($this->customer->fresh()->branch_id)->toBe($this->to->id);
});

it('shows segment tabs and bulk transfers from the customer list', function (): void {
    $other = Customer::factory()->forBranch($this->from)->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->from);
    Filament::bootCurrentPanel();

    livewire(ListCustomers::class)
        ->assertCanSeeTableRecords([$this->customer, $other])
        ->set('activeTab', CustomerSegment::Pending->value)
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$this->customer])
        ->set('activeTab', CustomerSegment::All->value)
        ->selectTableRecords([$this->customer->id, $other->id])
        ->callAction(TestAction::make('bulkTransfer')->table()->bulk(), ['branch_id' => $this->to->id])
        ->assertNotified();

    expect($this->customer->fresh()->branch_id)->toBe($this->to->id)
        ->and($other->fresh()->branch_id)->toBe($this->to->id);
});

it('renders the customer view page with its overview', function (): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->from);
    Filament::bootCurrentPanel();

    livewire(ViewCustomer::class, ['record' => $this->customer->getKey()])
        ->assertOk()
        ->assertSee($this->customer->customer_code)
        ->assertSee($this->account->account_number);
});
