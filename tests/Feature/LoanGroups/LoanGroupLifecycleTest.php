<?php

use App\Actions\GroupLoans\ApplyForGroupLoanAction;
use App\Actions\GroupLoans\ApproveGroupLoanAction;
use App\Actions\GroupLoans\DisburseGroupLoanAction;
use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Actions\LoanGroups\RemoveLoanGroupMemberAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoanGroup;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Validation\ValidationException;

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
});

it('adds members to a loan group with no rotation concept', function (): void {
    $customer = Customer::factory()->forBranch($this->branch)->create();

    $member = app(AddLoanGroupMemberAction::class)->execute($this->loanGroup, $customer);

    expect($member->status)->toBe('active')
        ->and($member->customer_id)->toBe($customer->id);
});

it('refuses to add the same customer twice while active', function (): void {
    $customer = Customer::factory()->forBranch($this->branch)->create();
    app(AddLoanGroupMemberAction::class)->execute($this->loanGroup, $customer);

    expect(fn () => app(AddLoanGroupMemberAction::class)->execute($this->loanGroup, $customer))
        ->toThrow(ValidationException::class);
});

it('removes a member with no active group loan', function (): void {
    $customer = Customer::factory()->forBranch($this->branch)->create();
    $member = app(AddLoanGroupMemberAction::class)->execute($this->loanGroup, $customer);

    $removed = app(RemoveLoanGroupMemberAction::class)->execute($member);

    expect($removed->status)->toBe('left')
        ->and($removed->left_at)->not->toBeNull();
});

it('blocks removing a member who is jointly liable on a disbursed, unclosed group loan', function (): void {
    $customers = collect(range(1, 2))->map(fn () => Customer::factory()->forBranch($this->branch)->create());
    $members = $customers->map(fn ($customer) => app(AddLoanGroupMemberAction::class)->execute($this->loanGroup, $customer));

    $groupLoan = app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->product, 1000_00);
    app(ApproveGroupLoanAction::class)->execute($groupLoan, $this->manager);
    app(DisburseGroupLoanAction::class)->execute($groupLoan->fresh(), $this->manager);

    expect(fn () => app(RemoveLoanGroupMemberAction::class)->execute($members->first()))
        ->toThrow(ValidationException::class);
});

it('refuses to apply for a group loan with fewer than 2 active members', function (): void {
    $customer = Customer::factory()->forBranch($this->branch)->create();
    app(AddLoanGroupMemberAction::class)->execute($this->loanGroup, $customer);

    expect(fn () => app(ApplyForGroupLoanAction::class)->execute($this->agent, $this->loanGroup->fresh(), $this->product, 1000_00))
        ->toThrow(ValidationException::class);
});
