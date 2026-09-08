<?php

use App\Actions\GroupLoans\ActivateGroupLoanAction;
use App\Actions\GroupLoans\IssueGroupMemberLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanDepositAction;
use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Actions\LoanGroups\RemoveLoanGroupMemberAction;
use App\Enums\LoanFrequency;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $this->loanGroup = LoanGroup::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
    ]);
});

function activateLoanFor(User $agent, LoanGroup $group, Customer $customer): GroupLoan
{
    $loan = app(IssueGroupMemberLoanAction::class)->execute(
        issuedBy: $agent,
        loanGroup: $group,
        customer: $customer,
        principal: 1000_00,
        securityDeposit: 100_00,
        periodicAmount: 100_00,
        frequency: LoanFrequency::Weekly,
        startDate: Carbon::now(),
    );

    app(RecordGroupLoanDepositAction::class)->execute($loan, 100_00, $agent);

    return app(ActivateGroupLoanAction::class)->execute($loan->fresh(), $agent);
}

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

it('removes a member with no active loan', function (): void {
    $customer = Customer::factory()->forBranch($this->branch)->create();
    $member = app(AddLoanGroupMemberAction::class)->execute($this->loanGroup, $customer);

    $removed = app(RemoveLoanGroupMemberAction::class)->execute($member);

    expect($removed->status)->toBe('left')
        ->and($removed->left_at)->not->toBeNull();
});

it('blocks removing a member who has an active loan', function (): void {
    $customer = Customer::factory()->forBranch($this->branch)->create();
    $loan = activateLoanFor($this->agent, $this->loanGroup->fresh(), $customer);
    $member = $loan->loanGroupMember;

    expect(fn () => app(RemoveLoanGroupMemberAction::class)->execute($member))
        ->toThrow(ValidationException::class);
});

it('reports the group outstanding as the sum of active member loans', function (): void {
    $a = Customer::factory()->forBranch($this->branch)->create();
    $b = Customer::factory()->forBranch($this->branch)->create();

    activateLoanFor($this->agent, $this->loanGroup->fresh(), $a);
    activateLoanFor($this->agent, $this->loanGroup->fresh(), $b);

    expect($this->loanGroup->fresh()->outstandingBalance())->toBe(2000_00);
});
