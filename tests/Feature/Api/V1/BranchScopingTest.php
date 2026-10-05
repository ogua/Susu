<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\Loan;
use App\Models\LoanGroup;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;

/**
 * Staff API access follows the same branch rule as the Filament panel:
 * a user only sees records of branches they belong to, while a company
 * admin sees every branch of their company.
 */
beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create(['company_id' => $this->branch->company_id]);

    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $this->otherCustomer = Customer::factory()->forBranch($this->otherBranch)->create();
    $this->otherAccount = SavingsAccount::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->otherCustomer->id,
    ]);
    $this->otherLoan = Loan::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->otherCustomer->id,
    ]);
    $this->otherLoanGroup = LoanGroup::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'company_id' => $this->branch->company_id,
    ]);
});

it('hides another branch\'s account statement from an agent', function (): void {
    $this->actingAs($this->agent, 'sanctum')
        ->getJson("/api/v1/agent/accounts/{$this->otherAccount->id}/statement-url")
        ->assertNotFound();
});

it('still lets an agent fetch the statement of an account assigned to them', function (): void {
    $this->otherAccount->forceFill(['agent_id' => $this->agent->id])->save();

    $this->actingAs($this->agent, 'sanctum')
        ->getJson("/api/v1/agent/accounts/{$this->otherAccount->id}/statement-url")
        ->assertOk();
});

it('hides another branch\'s customer from an agent', function (): void {
    $this->actingAs($this->agent, 'sanctum')
        ->getJson("/api/v1/agent/customers/{$this->otherCustomer->id}")
        ->assertNotFound();
});

it('hides another branch\'s loan from a manager, in show and index', function (): void {
    $this->actingAs($this->manager, 'sanctum')
        ->getJson("/api/v1/loans/{$this->otherLoan->id}")
        ->assertNotFound();

    $this->actingAs($this->manager, 'sanctum')
        ->getJson('/api/v1/loans')
        ->assertOk()
        ->assertJsonMissing(['id' => $this->otherLoan->id]);
});

it('hides another branch\'s customer group from staff', function (): void {
    $this->actingAs($this->manager, 'sanctum')
        ->getJson("/api/v1/loan-groups/{$this->otherLoanGroup->id}")
        ->assertNotFound();
});

it('hides another branch\'s group loan from staff', function (): void {
    $groupLoan = GroupLoan::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'company_id' => $this->branch->company_id,
        'loan_group_id' => $this->otherLoanGroup->id,
        'customer_id' => $this->otherCustomer->id,
    ]);

    $this->actingAs($this->manager, 'sanctum')
        ->getJson("/api/v1/group-loans/{$groupLoan->id}")
        ->assertNotFound();
});

it('hides another branch\'s ledger accounts from a branch manager', function (): void {
    $otherCash = app(ChartOfAccounts::class)->branchCash($this->otherBranch);

    $this->actingAs($this->manager, 'sanctum')
        ->getJson("/api/v1/ledger/accounts/{$otherCash->id}/entries")
        ->assertNotFound();

    $this->actingAs($this->manager, 'sanctum')
        ->getJson('/api/v1/ledger/accounts')
        ->assertOk()
        ->assertJsonMissing(['id' => $otherCash->id]);
});

it('lets a company admin see every branch of the company', function (): void {
    $admin = User::factory()->companyAdmin($this->branch->company)->create();
    $laterBranch = Branch::factory()->create(['company_id' => $this->branch->company_id]);
    $laterLoan = Loan::factory()->create([
        'branch_id' => $laterBranch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => Customer::factory()->forBranch($laterBranch)->create()->id,
    ]);

    $this->actingAs($admin, 'sanctum')->getJson("/api/v1/loans/{$this->otherLoan->id}")->assertOk();
    $this->actingAs($admin, 'sanctum')->getJson("/api/v1/loans/{$laterLoan->id}")->assertOk();
});
