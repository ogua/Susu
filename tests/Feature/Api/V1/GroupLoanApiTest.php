<?php

use App\Actions\GroupLoans\ApplyForGroupLoanAction;
use App\Actions\GroupLoans\ApproveGroupLoanAction;
use App\Actions\GroupLoans\DisburseGroupLoanAction;
use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\LoanProduct;
use App\Models\User;
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

    $this->product = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'min_amount' => 100_00,
        'max_amount' => 10_000_00,
    ]);

    $this->members = collect(range(1, 2))->map(function () {
        $customer = Customer::factory()->forBranch($this->branch)->create();

        return app(AddLoanGroupMemberAction::class)->execute($this->loanGroup, $customer);
    });
});

it('lets an agent apply for a group loan', function (): void {
    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/group-loans', [
        'loan_group_id' => $this->loanGroup->id,
        'loan_product_id' => $this->product->id,
        'amount' => 1000_00,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'applied')
        ->assertJsonPath('data.loan_group_id', $this->loanGroup->id);
});

it('rejects an amount outside the product min/max', function (): void {
    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/group-loans', [
        'loan_group_id' => $this->loanGroup->id,
        'loan_product_id' => $this->product->id,
        'amount' => 50_000_00,
    ])->assertUnprocessable();
});

it('rejects an application when the group has fewer than 2 active members, keyed on loan_group_id', function (): void {
    $thinGroup = LoanGroup::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
    ]);
    app(AddLoanGroupMemberAction::class)->execute($thinGroup, Customer::factory()->forBranch($this->branch)->create());

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/group-loans', [
        'loan_group_id' => $thinGroup->id,
        'loan_product_id' => $this->product->id,
        'amount' => 1000_00,
    ])->assertUnprocessable()->assertJsonValidationErrors('loan_group_id');
});

it('denies a customer role from viewing group loans (staff-only)', function (): void {
    $customerUser = User::factory()->customerUser()->create(['company_id' => $this->branch->company_id]);

    $this->actingAs($customerUser, 'sanctum')->getJson('/api/v1/group-loans')->assertForbidden();
});

it('lets an agent record a members repayment via the direct endpoint requiring group_loan_borrower_id', function (): void {
    $groupLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->product, 1000_00);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);
    $borrower = $disbursed->borrowers()->orderBy('created_at')->first();

    $response = $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/group-loans/{$disbursed->id}/repayments", [
        'group_loan_borrower_id' => $borrower->id,
        'amount' => 50_00,
    ]);

    $response->assertCreated()
        ->assertJsonPath('group_loan.outstanding_balance', $disbursed->outstanding_balance - 50_00);
});

it('rejects a repayment missing group_loan_borrower_id', function (): void {
    $groupLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->product, 1000_00);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);

    $this->actingAs($this->agent, 'sanctum')
        ->postJson("/api/v1/group-loans/{$disbursed->id}/repayments", ['amount' => 50_00])
        ->assertUnprocessable();
});

it('applies an offline group loan application and repayment through sync/batch', function (): void {
    $ref = (string) Str::uuid();

    $applyOp = [
        'op_id' => (string) Str::uuid(),
        'op_type' => 'group_loan.apply',
        'payload' => [
            'loan_group_id' => $this->loanGroup->id,
            'loan_product_id' => $this->product->id,
            'amount' => 1000_00,
            'client_reference' => $ref,
        ],
        'recorded_at' => now()->toISOString(),
    ];

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', ['ops' => [$applyOp]]);

    $response->assertOk()->assertJsonPath('results.0.status', 'applied');
    $groupLoanId = $response->json('results.0.result.group_loan_id');

    $groupLoan = GroupLoan::where('client_reference', $ref)->findOrFail($groupLoanId);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);
    $borrower = $disbursed->borrowers()->orderBy('created_at')->first();

    $repayOp = [
        'op_id' => (string) Str::uuid(),
        'op_type' => 'group_loan.repayment.record',
        'payload' => [
            'group_loan_id' => $disbursed->id,
            'group_loan_borrower_id' => $borrower->id,
            'amount' => 50_00,
        ],
        'recorded_at' => now()->toISOString(),
    ];

    $repayResponse = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', ['ops' => [$repayOp]]);

    $repayResponse->assertOk()->assertJsonPath('results.0.status', 'applied');
    expect($disbursed->fresh()->outstanding_balance)->toBe($disbursed->outstanding_balance - 50_00);
});

it('lets a branch manager restructure a group loan through the sync batch, with the desktop-generated new-loan id preserved', function (): void {
    $groupLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->product, 1000_00);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);

    $newLoanRef = (string) Str::uuid();

    $response = $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [[
            'op_id' => (string) Str::uuid(),
            'op_type' => 'group_loan.restructure',
            'payload' => [
                'group_loan_id' => $disbursed->id,
                'loan_product_id' => $this->product->id,
                'reason' => 'Group struggling with the old schedule',
                'client_reference' => $newLoanRef,
            ],
            'recorded_at' => now()->toISOString(),
        ]],
    ]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.0.result.group_loan_id', $newLoanRef);

    $newGroupLoan = GroupLoan::findOrFail($newLoanRef);
    expect($newGroupLoan->previous_group_loan_id)->toBe($disbursed->id)
        ->and($disbursed->fresh()->status->value)->toBe('refinanced');
});

it('lets a branch manager top up a group loan through the sync batch', function (): void {
    $groupLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->product, 1000_00);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);

    $newLoanRef = (string) Str::uuid();

    $response = $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [[
            'op_id' => (string) Str::uuid(),
            'op_type' => 'group_loan.top_up',
            'payload' => [
                'group_loan_id' => $disbursed->id,
                'amount' => 300_00,
                'reason' => 'Group requested more capital',
                'client_reference' => $newLoanRef,
            ],
            'recorded_at' => now()->toISOString(),
        ]],
    ]);

    $response->assertOk()->assertJsonPath('results.0.status', 'applied');

    $newGroupLoan = GroupLoan::findOrFail($newLoanRef);
    expect($newGroupLoan->principal_amount)->toBe($newGroupLoan->rolled_over_amount + 300_00);
});

it('rejects group loan restructure/top-up ops from a field agent', function (): void {
    $groupLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->product, 1000_00);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [[
            'op_id' => (string) Str::uuid(),
            'op_type' => 'group_loan.restructure',
            'payload' => ['group_loan_id' => $disbursed->id, 'loan_product_id' => $this->product->id, 'reason' => 'no'],
            'recorded_at' => now()->toISOString(),
        ]],
    ]);

    $response->assertOk()->assertJsonPath('results.0.status', 'rejected');
    expect($disbursed->fresh()->status->value)->toBe('disbursed');
});

it('lets a branch manager write off a group loan through the sync batch', function (): void {
    $groupLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->product, 1000_00);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);

    $response = $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [[
            'op_id' => (string) Str::uuid(),
            'op_type' => 'group_loan.write_off',
            'payload' => ['group_loan_id' => $disbursed->id, 'reason' => 'Group disbanded'],
            'recorded_at' => now()->toISOString(),
        ]],
    ]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.0.result.status', 'written_off');

    expect($disbursed->fresh()->status->value)->toBe('written_off');
});

it('rejects a group loan write-off op from a field agent', function (): void {
    $groupLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->product, 1000_00);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    $disbursed = app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);

    $response = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/sync/batch', [
        'ops' => [[
            'op_id' => (string) Str::uuid(),
            'op_type' => 'group_loan.write_off',
            'payload' => ['group_loan_id' => $disbursed->id, 'reason' => 'no'],
            'recorded_at' => now()->toISOString(),
        ]],
    ]);

    $response->assertOk()->assertJsonPath('results.0.status', 'rejected');
    expect($disbursed->fresh()->status->value)->toBe('disbursed');
});
