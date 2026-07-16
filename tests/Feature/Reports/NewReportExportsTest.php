<?php

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Reports\BuildAgentPerformanceReportAction;
use App\Actions\Reports\BuildCollectionsReportAction;
use App\Actions\Reports\BuildCustomerBalancesAction;
use App\Actions\Reports\BuildGroupReportAction;
use App\Actions\Reports\BuildLoanPortfolioReportAction;
use App\Actions\Reports\BuildWithdrawalsReportAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\LoanStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Group;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->companyAdmin = User::factory()->companyAdmin($this->branch->company)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $this->product = SavingsProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
    ]);

    $this->customer = Customer::factory()->forBranch($this->branch)->create();

    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $this->customer->id,
        'savings_product_id' => $this->product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);
});

it('builds the collections report and honours the date range', function (): void {
    $collect = app(RecordCollectionAction::class);
    $collect->execute($this->agent, $this->account, 500, recordedAt: now()->subDays(10));
    $collect->execute($this->agent, $this->account->fresh(), 500);

    $all = app(BuildCollectionsReportAction::class)->execute($this->branch);
    expect($all['totalCount'])->toBe(2)
        ->and($all['totalAmount'])->toBe(1000)
        ->and($all['agentSubtotals']->first()['agent'])->toBe($this->agent->name);

    $recent = app(BuildCollectionsReportAction::class)
        ->execute($this->branch, CarbonImmutable::now()->subDay());
    expect($recent['totalCount'])->toBe(1)
        ->and($recent['totalAmount'])->toBe(500);
});

it('lets a company admin export the collections report with a date filter', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $this->actingAs($this->companyAdmin)
        ->get(route('reports.collections.pdf', ['branch' => $this->branch, 'from' => now()->subDay()->toDateString()]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->companyAdmin)
        ->get(route('reports.collections.excel', $this->branch))
        ->assertOk();
});

it('rejects an inverted date range on the collections report', function (): void {
    $this->actingAs($this->companyAdmin)
        ->getJson(route('reports.collections.pdf', [
            'branch' => $this->branch,
            'from' => '2026-07-10',
            'to' => '2026-07-01',
        ]))
        ->assertUnprocessable();
});

it('builds the loan portfolio report with status summary and PAR', function (): void {
    $loanProduct = LoanProduct::factory()->create([
        'company_id' => $this->branch->company_id,
        'min_amount' => 100_00,
        'max_amount' => 1_000_00,
    ]);

    $loan = app(ApplyForLoanAction::class)->execute($this->agent, $this->customer, $loanProduct, 300_00);
    app(ApproveLoanAction::class)->execute($loan, $this->manager);
    app(DisburseLoanAction::class)->execute($loan->fresh(), $this->manager);

    $result = app(BuildLoanPortfolioReportAction::class)->execute($this->branch);

    expect($result['loans'])->toHaveCount(1)
        ->and($result['statusSummary']->first()['status'])->toBe(LoanStatus::Disbursed)
        ->and($result['totalPrincipal'])->toBe(300_00)
        ->and($result['parPercent'])->toBe(0.0);

    $filtered = app(BuildLoanPortfolioReportAction::class)
        ->execute($this->branch, status: LoanStatus::Applied);
    expect($filtered['loans'])->toBeEmpty();
});

it('lets a branch manager export the loan portfolio report', function (): void {
    $this->actingAs($this->manager)
        ->get(route('reports.loan-portfolio.pdf', $this->branch))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->manager)
        ->get(route('reports.loan-portfolio.excel', $this->branch))
        ->assertOk();
});

it('builds the agent performance report from day sheets and commissions', function (): void {
    $collect = app(RecordCollectionAction::class);
    $collect->execute($this->agent, $this->account, 500);
    $collect->execute($this->agent, $this->account->fresh(), 500);

    $result = app(BuildAgentPerformanceReportAction::class)->execute($this->branch);

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows']->first()['agent'])->toBe($this->agent->name)
        ->and($result['rows']->first()['collections_total'])->toBe(1000)
        ->and($result['rows']->first()['collections_count'])->toBe(2)
        ->and($result['totals']['collections_total'])->toBe(1000);
});

it('lets a company admin export the agent performance report', function (): void {
    $this->actingAs($this->companyAdmin)
        ->get(route('reports.agent-performance.pdf', $this->branch))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('builds the withdrawals report with status totals', function (): void {
    WithdrawalRequest::factory()->count(2)->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'savings_account_id' => $this->account->id,
        'customer_id' => $this->customer->id,
        'amount' => 200,
        'status' => WithdrawalStatus::Pending,
    ]);

    $result = app(BuildWithdrawalsReportAction::class)->execute($this->branch);

    expect($result['requests'])->toHaveCount(2)
        ->and($result['totalAmount'])->toBe(400)
        ->and($result['statusTotals']->first()['status'])->toBe(WithdrawalStatus::Pending);

    $paidOnly = app(BuildWithdrawalsReportAction::class)
        ->execute($this->branch, status: WithdrawalStatus::Paid);
    expect($paidOnly['requests'])->toBeEmpty();
});

it('lets a branch manager export the withdrawals report', function (): void {
    $this->actingAs($this->manager)
        ->get(route('reports.withdrawals.pdf', $this->branch))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('builds the group report with membership and round progress', function (): void {
    Group::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
    ]);

    $result = app(BuildGroupReportAction::class)->execute($this->branch);

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows']->first()['members_count'])->toBe(0)
        ->and($result['totalCollectedInPeriod'])->toBe(0);
});

it('lets a company admin export the group report', function (): void {
    $this->actingAs($this->companyAdmin)
        ->get(route('reports.groups.pdf', $this->branch))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('builds the customer balances listing for open accounts only', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $result = app(BuildCustomerBalancesAction::class)->execute($this->branch);

    expect($result['accounts'])->toHaveCount(1)
        ->and($result['totalBalance'])->toBe((int) $this->account->fresh()->balance);
});

it('lets a company admin export the customer balances report', function (): void {
    $this->actingAs($this->companyAdmin)
        ->get(route('reports.customer-balances.pdf', $this->branch))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->companyAdmin)
        ->get(route('reports.customer-balances.excel', $this->branch))
        ->assertOk();
});

it('denies a field agent the new reports', function (): void {
    $this->actingAs($this->agent)
        ->get(route('reports.collections.pdf', $this->branch))
        ->assertForbidden();
});

it('denies a manager from another company the new reports', function (): void {
    $otherBranch = Branch::factory()->create();
    $otherManager = User::factory()->branchManager($otherBranch)->create();

    $this->actingAs($otherManager)
        ->get(route('reports.customer-balances.pdf', $this->branch))
        ->assertNotFound();
});
