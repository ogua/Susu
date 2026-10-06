<?php

use App\Actions\Customers\ProvisionCustomerLoginAction;
use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Http\Controllers\Api\V1\ReportController;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Group;
use App\Models\GroupLoan;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\LoanGroup;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Every read endpoint, called as each role the mobile/desktop apps log in
 * with. Guards against controllers that assume a home branch (company admins
 * have none) or forget a role entirely.
 */
beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->companyAdmin = User::factory()->companyAdmin($this->branch->company)->create(['branch_id' => null]);
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $this->product = SavingsProduct::factory()->create(['company_id' => $this->branch->company_id, 'contribution_amount' => 500]);
    $this->customer = Customer::factory()->forBranch($this->branch)->create(['assigned_agent_id' => $this->agent->id]);
    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);
    $this->customerUser = app(ProvisionCustomerLoginAction::class)->execute($this->customer, 'password123');

    $this->loan = Loan::factory()->create(['branch_id' => $this->branch->id, 'customer_id' => $this->customer->id, 'agent_id' => $this->agent->id]);
    $this->loanGroup = LoanGroup::factory()->create(['branch_id' => $this->branch->id]);
    app(AddLoanGroupMemberAction::class)->execute($this->loanGroup, $this->customer);
    $this->groupLoan = GroupLoan::factory()->create(['branch_id' => $this->branch->id, 'loan_group_id' => $this->loanGroup->id, 'customer_id' => $this->customer->id, 'agent_id' => $this->agent->id]);
    $this->group = Group::factory()->create(['branch_id' => $this->branch->id]);
    $this->intent = PaymentIntent::factory()->create(['branch_id' => $this->branch->id]);
    $this->ledgerAccount = LedgerAccount::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id]);
});

/**
 * @return array<int, string>
 */
function roleMatrixGetUrls(object $test): array
{
    return [
        '/api/v1/auth/me',
        '/api/v1/sync/bootstrap',
        '/api/v1/sync/delta?since='.urlencode(now()->subDay()->toIso8601String()),
        '/api/v1/agent/accounts',
        "/api/v1/agent/accounts/{$test->account->id}/statement-url",
        "/api/v1/agent/customers/{$test->customer->id}",
        '/api/v1/agent/summary/today',
        '/api/v1/customer/accounts',
        "/api/v1/customer/accounts/{$test->account->id}/transactions",
        '/api/v1/customer/withdrawal-requests',
        '/api/v1/customers',
        '/api/v1/customers/overview',
        '/api/v1/payments',
        "/api/v1/payments/{$test->intent->id}",
        '/api/v1/loans',
        '/api/v1/loans/products',
        "/api/v1/loans/eligibility?customer_id={$test->customer->id}",
        "/api/v1/loans/{$test->loan->id}",
        '/api/v1/loan-groups',
        "/api/v1/loan-groups/{$test->loanGroup->id}",
        "/api/v1/loan-groups/{$test->loanGroup->id}/history",
        '/api/v1/collection-sheet',
        '/api/v1/collection-sheet?all_customers=1',
        '/api/v1/group-loans',
        "/api/v1/group-loans/{$test->groupLoan->id}",
        '/api/v1/dashboard/agent',
        '/api/v1/dashboard/branch',
        '/api/v1/dashboard/customer',
        '/api/v1/agents/positions',
        "/api/v1/agents/{$test->agent->id}/route",
        ...array_map(fn (string $report): string => "/api/v1/reports/{$report}", ReportController::REPORTS),
        '/api/v1/reports/collections/download-url?format=pdf',
        '/api/v1/ledger/accounts',
        "/api/v1/ledger/accounts/{$test->ledgerAccount->id}/entries",
        '/api/v1/groups',
        "/api/v1/groups/{$test->group->id}",
    ];
}

dataset('roles', ['companyAdmin', 'manager', 'agent', 'customerUser']);

it('never errors on a read endpoint for any role', function (string $role): void {
    $failures = [];

    foreach (roleMatrixGetUrls($this) as $url) {
        $response = $this->actingAs($this->{$role}, 'sanctum')->getJson($url);

        if ($response->status() >= 500) {
            $failures[] = "{$url} → {$response->status()}: ".($response->json('message') ?? '');
        }
    }

    expect($failures)->toBe([]);
})->with('roles');

dataset('staff', ['companyAdmin', 'manager', 'agent']);

it('tells the app each user\'s roles and home branch', function (): void {
    $this->actingAs($this->companyAdmin, 'sanctum')->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.role', 'company_admin')
        ->assertJsonPath('user.roles', ['company_admin'])
        ->assertJsonPath('user.branch_id', null);

    $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/auth/me')
        ->assertJsonPath('user.role', 'branch_manager')
        ->assertJsonPath('user.branch_id', $this->branch->id);
});

it('shows managers and admins every account in the branch, but agents only their own', function (): void {
    $otherAgent = User::factory()->fieldAgent($this->branch)->create();

    foreach (['companyAdmin', 'manager', 'agent'] as $role) {
        expect($this->actingAs($this->{$role}, 'sanctum')->getJson('/api/v1/agent/accounts')->assertOk()->json('data.*.id'))
            ->toContain($this->account->id);

        $bootstrap = $this->actingAs($this->{$role}, 'sanctum')->getJson('/api/v1/sync/bootstrap')->assertOk();
        expect($bootstrap->json('accounts.*.id'))->toContain($this->account->id)
            ->and($bootstrap->json('customers.*.id'))->toContain($this->customer->id);
    }

    $this->actingAs($otherAgent, 'sanctum')->getJson('/api/v1/agent/accounts')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($otherAgent, 'sanctum')->getJson('/api/v1/sync/bootstrap')->assertOk()->assertJsonCount(0, 'accounts');
});

it('never leaks another company\'s branch through branch_id', function (): void {
    $foreign = Branch::factory()->create();

    foreach (['/api/v1/agent/accounts', '/api/v1/sync/bootstrap', '/api/v1/collection-sheet', '/api/v1/dashboard/branch'] as $url) {
        $this->actingAs($this->companyAdmin, 'sanctum')->getJson("{$url}?branch_id={$foreign->id}")->assertNotFound();
        $this->actingAs($this->manager, 'sanctum')->getJson("{$url}?branch_id={$foreign->id}")->assertNotFound();
    }
});

it('gives managers and admins the whole branch on the collection sheet', function (string $role): void {
    $this->actingAs($this->{$role}, 'sanctum')->getJson('/api/v1/collection-sheet?all_customers=1')
        ->assertOk()
        ->assertJsonPath('meta.branch_id', $this->branch->id)
        ->assertJsonPath('meta.officer_id', null)
        ->assertJsonFragment(['customer_id' => $this->customer->id]);
})->with(['companyAdmin', 'manager']);

it('lists branch records for a company admin without a home branch', function (): void {
    $admin = $this->actingAs($this->companyAdmin, 'sanctum');

    expect($admin->getJson('/api/v1/groups')->assertOk()->json('data.*.id'))->toContain($this->group->id)
        ->and($admin->getJson('/api/v1/customers')->assertOk()->json('data.*.id'))->toContain($this->customer->id)
        ->and($admin->getJson('/api/v1/loans')->assertOk()->json('data.*.id'))->toContain($this->loan->id)
        ->and($admin->getJson('/api/v1/loan-groups')->assertOk()->json('data.*.id'))->toContain($this->loanGroup->id);
    $admin->getJson('/api/v1/agent/summary/today')->assertOk()->assertJsonPath('summary.collections_total', 0);
});

it('lets every staff role collect, register customers and close the day', function (string $role): void {
    $user = $this->{$role};

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/agent/collections', [
        'savings_account_id' => $this->account->id,
        'amount' => 500,
        'client_reference' => (string) Str::uuid(),
    ])->assertCreated();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/agent/customers', [
        'first_name' => 'Direct',
        'last_name' => 'Register',
        'phone' => '0240000001',
    ])->assertCreated()->assertJsonPath('data.branch_id', $this->branch->id);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/sync/batch', ['ops' => [[
        'op_id' => (string) Str::uuid(),
        'op_type' => 'customer.register',
        'payload' => ['first_name' => 'Offline', 'last_name' => 'Register', 'phone' => '0240000002', 'client_reference' => (string) Str::uuid()],
        'recorded_at' => now()->toISOString(),
    ]]])->assertOk()->assertJsonPath('results.0.status', 'applied');

    expect(Customer::where('first_name', 'Offline')->value('branch_id'))->toBe($this->branch->id);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/agent/summary/today')
        ->assertOk()->assertJsonPath('summary.collections_total', 500);

    // Closing a day is a field-agent duty, as on the web Close Daily page.
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/agent/summaries', ['declared_cash' => 500])
        ->assertStatus($role === 'agent' ? 200 : 403);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/agent/remittances', ['amount' => 500])->assertCreated();

    // Creating customer groups is manager-tier (LoanGroupPolicy::create).
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/loan-groups', ['name' => "Group by {$role}", 'code' => strtoupper($role)])
        ->assertStatus($role === 'agent' ? 403 : 201);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/agent/duty', ['on_duty' => true])->assertOk();
})->with('staff');
