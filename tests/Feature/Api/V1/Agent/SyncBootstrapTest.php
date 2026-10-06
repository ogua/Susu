<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Group;
use App\Models\GroupLoan;
use App\Models\Loan;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
});

it('includes the company-wide active product catalogue in bootstrap', function (): void {
    $active = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'is_active' => true,
    ]);
    $inactive = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'is_active' => false,
    ]);
    // Another company's product must never leak into this agent's catalogue.
    $otherCompanyProduct = SavingsProduct::factory()->create(['is_active' => true]);

    $response = $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/sync/bootstrap');

    $response->assertOk();
    $ids = collect($response->json('products'))->pluck('id');

    expect($ids)->toContain($active->id)
        ->and($ids)->not->toContain($inactive->id)
        ->and($ids)->not->toContain($otherCompanyProduct->id);
});

/** An account assigned to the agent, so its customer and branch are in the agent's working set. */
function agentWorkingCustomer(Branch $branch, User $agent): Customer
{
    $customer = Customer::factory()->forBranch($branch)->create();
    SavingsAccount::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'savings_product_id' => SavingsProduct::factory()->create(['company_id' => $branch->company_id])->id,
        'agent_id' => $agent->id,
    ]);

    return $customer;
}

it('includes open loans, group loans, groups and customer groups in bootstrap', function (): void {
    $customer = agentWorkingCustomer($this->branch, $this->agent);
    $otherBranch = Branch::factory()->create(['company_id' => $this->branch->company_id]);

    $openLoan = Loan::factory()->create(['branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'status' => 'disbursed']);
    $closedLoan = Loan::factory()->create(['branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'status' => 'closed']);
    $strangersLoan = Loan::factory()->create(['branch_id' => $this->branch->id, 'status' => 'disbursed']);

    $loanGroup = LoanGroup::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);
    $member = LoanGroupMember::factory()->create(['loan_group_id' => $loanGroup->id, 'customer_id' => $customer->id]);
    $groupLoan = GroupLoan::factory()->create([
        'branch_id' => $this->branch->id,
        'loan_group_id' => $loanGroup->id,
        'loan_group_member_id' => $member->id,
        'status' => 'active',
    ]);

    $group = Group::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $otherBranchGroup = Group::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $otherBranch->id]);
    $activeProduct = LoanProduct::factory()->create(['company_id' => $this->branch->company_id, 'is_active' => true]);

    $response = $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/sync/bootstrap')->assertOk();

    expect(collect($response->json('loans'))->pluck('id')->all())->toBe([$openLoan->id])
        ->and($response->json('loans.0.installments'))->toBeArray()
        ->and(collect($response->json('group_loans'))->pluck('id')->all())->toBe([$groupLoan->id])
        ->and(collect($response->json('groups'))->pluck('id')->all())->toBe([$group->id])
        ->and(collect($response->json('loan_groups'))->pluck('id')->all())->toBe([$loanGroup->id])
        ->and(collect($response->json('loan_products'))->pluck('id'))->toContain($activeProduct->id)
        ->and($closedLoan->id)->not->toBeIn(collect($response->json('loans'))->pluck('id')->all())
        ->and($strangersLoan->id)->not->toBeIn(collect($response->json('loans'))->pluck('id')->all())
        ->and($otherBranchGroup->id)->not->toBeIn(collect($response->json('groups'))->pluck('id')->all());
});

it('sends loans and groups changed since the cursor in delta, closed ones included', function (): void {
    $customer = agentWorkingCustomer($this->branch, $this->agent);
    $loan = Loan::factory()->create(['branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'status' => 'disbursed']);
    $group = Group::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $untouched = Group::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);

    $cursor = now()->toISOString();
    $this->travel(1)->minutes();
    $loan->update(['status' => 'closed']);
    $group->update(['status' => 'completed']);

    $response = $this->actingAs($this->agent, 'sanctum')
        ->getJson('/api/v1/sync/delta?cursor='.urlencode($cursor))
        ->assertOk();

    expect(collect($response->json('loans'))->pluck('id')->all())->toBe([$loan->id])
        ->and($response->json('loans.0.status'))->toBe('closed')
        ->and(collect($response->json('groups'))->pluck('id')->all())->toBe([$group->id])
        ->and($untouched->id)->not->toBeIn(collect($response->json('groups'))->pluck('id')->all());
});
