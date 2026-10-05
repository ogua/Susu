<?php

use App\Actions\GroupLoans\ActivateGroupLoanAction;
use App\Actions\GroupLoans\IssueGroupMemberLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanDepositAction;
use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Enums\LoanFrequency;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\SavingsAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
    $this->savingsAccount = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
    ]);
});

function issuePayload(Customer $customer, LoanGroup $group, array $overrides = []): array
{
    return array_merge([
        'loan_group_id' => $group->id,
        'customer_id' => $customer->id,
        'principal_amount' => 1000_00,
        'security_deposit_amount' => 100_00,
        'periodic_amount' => 100_00,
        'repayment_frequency' => 'weekly',
        'start_date' => Carbon::now()->toDateString(),
    ], $overrides);
}

function apiIssuedLoan(User $agent, Customer $customer, LoanGroup $group): GroupLoan
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

it('lets an agent issue a member loan', function (): void {
    $response = $this->actingAs($this->agent, 'sanctum')
        ->postJson('/api/v1/group-loans', issuePayload($this->customer, $this->loanGroup));

    $response->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.deposit_status', 'pending')
        ->assertJsonPath('data.total_periods', 10)
        ->assertJsonPath('data.loan_group_id', $this->loanGroup->id);
});

it('rejects bad issue amounts', function (): void {
    $this->actingAs($this->agent, 'sanctum')
        ->postJson('/api/v1/group-loans', issuePayload($this->customer, $this->loanGroup, ['principal_amount' => 0]))
        ->assertUnprocessable();

    $this->actingAs($this->agent, 'sanctum')
        ->postJson('/api/v1/group-loans', issuePayload($this->customer, $this->loanGroup, ['start_date' => Carbon::now()->subDay()->toDateString()]))
        ->assertUnprocessable();
});

it('records a deposit into the chosen savings account, activates, and records a repayment', function (): void {
    $loan = apiIssuedLoan($this->agent, $this->customer, $this->loanGroup);

    $this->actingAs($this->agent, 'sanctum')
        ->postJson("/api/v1/group-loans/{$loan->id}/deposit", [
            'savings_account_id' => $this->savingsAccount->id,
            'amount' => 100_00,
        ])
        ->assertCreated()
        ->assertJsonPath('group_loan.deposit_status', 'held');

    expect($this->savingsAccount->fresh()->balance)->toBe(100_00);

    $this->actingAs($this->agent, 'sanctum')
        ->postJson("/api/v1/group-loans/{$loan->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonCount(10, 'data.installments');

    $this->actingAs($this->agent, 'sanctum')
        ->postJson("/api/v1/group-loans/{$loan->id}/repayments", ['amount' => 300_00])
        ->assertCreated()
        ->assertJsonPath('group_loan.outstanding_balance', 700_00);
});

it('rejects a deposit request missing the savings account', function (): void {
    $loan = apiIssuedLoan($this->agent, $this->customer, $this->loanGroup);

    $this->actingAs($this->agent, 'sanctum')
        ->postJson("/api/v1/group-loans/{$loan->id}/deposit", ['amount' => 100_00])
        ->assertUnprocessable();
});

it('writes off with savings applied via the endpoint', function (): void {
    $loan = apiIssuedLoan($this->agent, $this->customer, $this->loanGroup);
    app(RecordGroupLoanDepositAction::class)->execute($loan, $this->savingsAccount, 100_00, $this->agent);
    $active = app(ActivateGroupLoanAction::class)->execute($loan->fresh(), $this->agent);
    app(RecordGroupLoanRepaymentAction::class)->execute($active, 200_00, $this->agent);

    $this->actingAs($this->manager, 'sanctum')
        ->postJson("/api/v1/group-loans/{$loan->id}/write-off", [
            'reason' => 'uncollectible',
            'savings_account_id' => $this->savingsAccount->id,
            'savings_amount_applied' => 100_00,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'written_off')
        ->assertJsonPath('data.write_off_savings_applied', 100_00);
});

it('denies a customer role from group loan endpoints (staff-only)', function (): void {
    $customerUser = User::factory()->customerUser()->create(['company_id' => $this->branch->company_id]);

    $this->actingAs($customerUser, 'sanctum')->getJson('/api/v1/group-loans')->assertForbidden();
});

it('exposes the group outstanding and member active-loan summary on loan-groups', function (): void {
    $loan = apiIssuedLoan($this->agent, $this->customer, $this->loanGroup);
    app(RecordGroupLoanDepositAction::class)->execute($loan, $this->savingsAccount, 100_00, $this->agent);
    app(ActivateGroupLoanAction::class)->execute($loan->fresh(), $this->agent);

    $this->actingAs($this->agent, 'sanctum')
        ->getJson("/api/v1/loan-groups/{$this->loanGroup->id}")
        ->assertOk()
        ->assertJsonPath('data.group_outstanding', 1000_00)
        ->assertJsonPath('data.members.0.active_loan.outstanding_balance', 1000_00);
});

it('exposes a draft loan as open_loan so clients stop offering issue loan', function (): void {
    $loan = apiIssuedLoan($this->agent, $this->customer, $this->loanGroup);

    $this->actingAs($this->agent, 'sanctum')
        ->getJson("/api/v1/loan-groups/{$this->loanGroup->id}")
        ->assertOk()
        ->assertJsonPath('data.members.0.active_loan', null)
        ->assertJsonPath('data.members.0.open_loan.id', $loan->id)
        ->assertJsonPath('data.members.0.open_loan.status', 'draft');
});

it('lets an agent cancel a draft loan directly and through /sync/batch', function (): void {
    $direct = apiIssuedLoan($this->agent, $this->customer, $this->loanGroup);

    $this->actingAs($this->agent, 'sanctum')
        ->postJson("/api/v1/group-loans/{$direct->id}/cancel", ['reason' => 'Wrong amount'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation_reason', 'Wrong amount');

    $synced = apiIssuedLoan($this->agent, $this->customer, $this->loanGroup->fresh());

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [[
            'op_id' => (string) Str::uuid(),
            'op_type' => 'group_loan.cancel',
            'recorded_at' => Carbon::now()->toISOString(),
            'payload' => ['group_loan_id' => $synced->id],
        ]],
    ]);

    $response->assertOk();
    expect($response->json('results.0.status'))->toBe('applied')
        ->and($synced->fresh()->status->value)->toBe('cancelled');
});

it('refuses to cancel an active loan through the API', function (): void {
    $loan = apiIssuedLoan($this->agent, $this->customer, $this->loanGroup);
    app(RecordGroupLoanDepositAction::class)->execute($loan, $this->savingsAccount, 100_00, $this->agent);
    app(ActivateGroupLoanAction::class)->execute($loan->fresh(), $this->agent);

    $this->actingAs($this->agent, 'sanctum')
        ->postJson("/api/v1/group-loans/{$loan->id}/cancel")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('replays an offline issue -> deposit -> activate -> repayment through /sync/batch', function (): void {
    $loanId = (string) Str::uuid();
    $now = Carbon::now()->toISOString();

    $ops = [
        ['op_id' => (string) Str::uuid(), 'op_type' => 'group_loan.issue', 'recorded_at' => $now, 'payload' => issuePayload($this->customer, $this->loanGroup, [
            'client_reference' => $loanId,
            'security_deposit_amount' => 200_00,
        ])],
        ['op_id' => (string) Str::uuid(), 'op_type' => 'group_loan.deposit.record', 'recorded_at' => $now, 'payload' => [
            'group_loan_id' => $loanId, 'savings_account_id' => $this->savingsAccount->id, 'amount' => 200_00, 'client_reference' => (string) Str::uuid(),
        ]],
        ['op_id' => (string) Str::uuid(), 'op_type' => 'group_loan.activate', 'recorded_at' => $now, 'payload' => [
            'group_loan_id' => $loanId,
        ]],
        ['op_id' => (string) Str::uuid(), 'op_type' => 'group_loan.repayment.record', 'recorded_at' => $now, 'payload' => [
            'group_loan_id' => $loanId, 'amount' => 300_00, 'client_reference' => (string) Str::uuid(),
        ]],
    ];

    $response = $this->actingAs($this->agent, 'sanctum')
        ->withHeader('X-Client-Origin', 'desktop')
        ->postJson('/api/v1/sync/batch', ['ops' => $ops]);

    $response->assertOk();
    expect(collect($response->json('results'))->pluck('status')->all())->each->toBe('applied');

    $loan = GroupLoan::findOrFail($loanId);
    expect($loan->status->value)->toBe('active')
        ->and($loan->deposit_status->value)->toBe('held')
        // 1000 principal - 300 cash repaid = 700
        ->and($loan->outstanding_balance)->toBe(700_00)
        ->and($this->savingsAccount->fresh()->balance)->toBe(200_00);
});

it('lets a field agent issue/deposit/repay via sync but denies write-off', function (): void {
    $loan = apiIssuedLoan($this->agent, $this->customer, $this->loanGroup);
    app(RecordGroupLoanDepositAction::class)->execute($loan, $this->savingsAccount, 100_00, $this->agent);
    app(ActivateGroupLoanAction::class)->execute($loan->fresh(), $this->agent);

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [[
            'op_id' => (string) Str::uuid(),
            'op_type' => 'group_loan.write_off',
            'recorded_at' => Carbon::now()->toISOString(),
            'payload' => ['group_loan_id' => $loan->id, 'reason' => 'test'],
        ]],
    ]);

    $response->assertOk();
    expect($response->json('results.0.status'))->toBe('rejected');
});

it('lets a branch manager write off a group loan via sync', function (): void {
    $loan = apiIssuedLoan($this->manager, $this->customer, $this->loanGroup);
    app(RecordGroupLoanDepositAction::class)->execute($loan, $this->savingsAccount, 100_00, $this->manager);
    app(ActivateGroupLoanAction::class)->execute($loan->fresh(), $this->manager);

    $response = $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [[
            'op_id' => (string) Str::uuid(),
            'op_type' => 'group_loan.write_off',
            'recorded_at' => Carbon::now()->toISOString(),
            'payload' => ['group_loan_id' => $loan->id, 'reason' => 'uncollectible'],
        ]],
    ]);

    $response->assertOk();
    expect($response->json('results.0.status'))->toBe('applied')
        ->and(GroupLoan::find($loan->id)->status->value)->toBe('written_off');
});
