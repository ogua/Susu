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

it('records a deposit, activates, and records a repayment', function (): void {
    $loan = apiIssuedLoan($this->agent, $this->customer, $this->loanGroup);

    $this->actingAs($this->agent, 'sanctum')
        ->postJson("/api/v1/group-loans/{$loan->id}/deposit", ['amount' => 100_00])
        ->assertCreated()
        ->assertJsonPath('group_loan.deposit_status', 'held');

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

it('applies the deposit to the balance via the endpoint', function (): void {
    $loan = apiIssuedLoan($this->agent, $this->customer, $this->loanGroup);
    app(RecordGroupLoanDepositAction::class)->execute($loan, 100_00, $this->agent);
    $active = app(ActivateGroupLoanAction::class)->execute($loan->fresh(), $this->agent);
    app(RecordGroupLoanRepaymentAction::class)->execute($active, 200_00, $this->agent);

    $this->actingAs($this->agent, 'sanctum')
        ->postJson("/api/v1/group-loans/{$loan->id}/apply-deposit")
        ->assertOk()
        ->assertJsonPath('group_loan.outstanding_balance', 700_00)
        ->assertJsonPath('group_loan.deposit_status', 'settled');
});

it('denies a customer role from group loan endpoints (staff-only)', function (): void {
    $customerUser = User::factory()->customerUser()->create(['company_id' => $this->branch->company_id]);

    $this->actingAs($customerUser, 'sanctum')->getJson('/api/v1/group-loans')->assertForbidden();
});

it('exposes the group outstanding and member active-loan summary on loan-groups', function (): void {
    $loan = apiIssuedLoan($this->agent, $this->customer, $this->loanGroup);
    app(RecordGroupLoanDepositAction::class)->execute($loan, 100_00, $this->agent);
    app(ActivateGroupLoanAction::class)->execute($loan->fresh(), $this->agent);

    $this->actingAs($this->agent, 'sanctum')
        ->getJson("/api/v1/loan-groups/{$this->loanGroup->id}")
        ->assertOk()
        ->assertJsonPath('data.group_outstanding', 1000_00)
        ->assertJsonPath('data.members.0.active_loan.outstanding_balance', 1000_00);
});

it('replays an offline issue -> deposit -> activate -> repayment -> apply-deposit through /sync/batch', function (): void {
    $loanId = (string) Str::uuid();
    $now = Carbon::now()->toISOString();

    $ops = [
        ['op_id' => (string) Str::uuid(), 'op_type' => 'group_loan.issue', 'recorded_at' => $now, 'payload' => issuePayload($this->customer, $this->loanGroup, [
            'client_reference' => $loanId,
            'security_deposit_amount' => 200_00,
        ])],
        ['op_id' => (string) Str::uuid(), 'op_type' => 'group_loan.deposit.record', 'recorded_at' => $now, 'payload' => [
            'group_loan_id' => $loanId, 'amount' => 200_00, 'client_reference' => (string) Str::uuid(),
        ]],
        ['op_id' => (string) Str::uuid(), 'op_type' => 'group_loan.activate', 'recorded_at' => $now, 'payload' => [
            'group_loan_id' => $loanId,
        ]],
        ['op_id' => (string) Str::uuid(), 'op_type' => 'group_loan.repayment.record', 'recorded_at' => $now, 'payload' => [
            'group_loan_id' => $loanId, 'amount' => 300_00, 'client_reference' => (string) Str::uuid(),
        ]],
        ['op_id' => (string) Str::uuid(), 'op_type' => 'group_loan.deposit.apply', 'recorded_at' => $now, 'payload' => [
            'group_loan_id' => $loanId, 'client_reference' => (string) Str::uuid(),
        ]],
    ];

    $response = $this->actingAs($this->agent, 'sanctum')
        ->withHeader('X-Client-Origin', 'desktop')
        ->postJson('/api/v1/sync/batch', ['ops' => $ops]);

    $response->assertOk();
    expect(collect($response->json('results'))->pluck('status')->all())->each->toBe('applied');

    $loan = GroupLoan::findOrFail($loanId);
    expect($loan->status->value)->toBe('active')
        ->and($loan->deposit_status->value)->toBe('settled')
        // 1000 principal - 300 cash - 200 deposit applied = 500
        ->and($loan->outstanding_balance)->toBe(500_00);
});

it('lets a field agent issue/deposit/repay via sync but denies write-off', function (): void {
    $loan = apiIssuedLoan($this->agent, $this->customer, $this->loanGroup);
    app(RecordGroupLoanDepositAction::class)->execute($loan, 100_00, $this->agent);
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
    app(RecordGroupLoanDepositAction::class)->execute($loan, 100_00, $this->manager);
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
