<?php

use App\Actions\Reports\BuildBalanceSheetAction;
use App\Actions\Reports\BuildGeneralLedgerAction;
use App\Actions\Reports\BuildIncomeStatementAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\ClientOrigin;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Filament\Resources\LedgerAccounts\Pages\AccountLedger;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->companyAdmin = User::factory()->companyAdmin($this->branch->company)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();

    $product = SavingsProduct::factory()->firstContributionCommission()->create([
        'company_id' => $this->branch->company_id,
        'contribution_amount' => 500,
    ]);
    $customer = Customer::factory()->forBranch($this->branch)->create();
    $this->account = SavingsAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->branch->company_id,
        'customer_id' => $customer->id,
        'savings_product_id' => $product->id,
        'agent_id' => $this->agent->id,
        'contribution_amount' => 500,
    ]);
});

it('builds a detailed general ledger with opening and running balances', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500, recordedAt: now()->subDays(10));
    app(RecordCollectionAction::class)->execute($this->agent, $this->account->fresh(), 500);

    $agentCash = app(ChartOfAccounts::class)->agentCash($this->agent);

    $all = app(BuildGeneralLedgerAction::class)->execute($this->branch->company, $agentCash);
    expect($all['mode'])->toBe('detailed')
        ->and($all['rows'])->toHaveCount(2)
        ->and($all['openingBalance'])->toBe(0)
        ->and($all['closingBalance'])->toBe(1000)
        ->and($all['rows']->last()['running'])->toBe(1000)
        ->and($all['totalDebits'])->toBe(1000);

    $recent = app(BuildGeneralLedgerAction::class)
        ->execute($this->branch->company, $agentCash, CarbonImmutable::now()->subDay());
    expect($recent['rows'])->toHaveCount(1)
        ->and($recent['openingBalance'])->toBe(500)
        ->and($recent['closingBalance'])->toBe(1000);
});

it('builds a summary general ledger whose debits equal credits', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $result = app(BuildGeneralLedgerAction::class)->execute($this->branch->company);

    expect($result['mode'])->toBe('summary')
        ->and($result['totalDebits'])->toBe($result['totalCredits'])
        ->and($result['totalDebits'])->toBeGreaterThan(0);
});

it('builds an income statement from posted income and expense lines', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $chart = app(ChartOfAccounts::class);
    app(LedgerService::class)->post(new EntryData(
        company: $this->branch->company,
        type: TransactionType::Commission,
        lines: [
            ['account' => $chart->agentCash($this->agent), 'debit' => 200],
            ['account' => $chart->commissionIncome($this->branch->company), 'credit' => 200],
        ],
        branch: $this->branch,
        paymentMethod: PaymentMethod::Internal,
        origin: ClientOrigin::System,
        recordedBy: $this->agent,
        description: 'Test commission',
    ));

    $result = app(BuildIncomeStatementAction::class)->execute($this->branch->company);

    // 500 cycle commission (FirstContributionPerCycle product) + 200 manual entry.
    expect($result['totalIncome'])->toBe(700)
        ->and($result['totalExpenses'])->toBe(0)
        ->and($result['netIncome'])->toBe(700)
        ->and($result['incomeRows']->first()['code'])->toBe('4100-COMM');
});

it('builds a balanced balance sheet', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);

    $result = app(BuildBalanceSheetAction::class)->execute($this->branch->company);

    // The whole first contribution is cycle commission, so the customer
    // liability nets to zero and the 500 sits in retained earnings.
    expect($result['isBalanced'])->toBeTrue()
        ->and($result['totalAssets'])->toBe(500)
        ->and($result['totalLiabilities'])->toBe(0)
        ->and($result['retainedEarnings'])->toBe(500);
});

it('renders the account ledger page with running balances', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);
    $agentCash = app(ChartOfAccounts::class)->agentCash($this->agent);

    $this->actingAs($this->manager);
    bootAdminPanelWithTenant($this->branch);

    $page = livewire(AccountLedger::class, ['record' => $agentCash->id])->assertOk();

    expect($page->instance()->currentBalance())->toBe(500)
        ->and($page->instance()->openingBalance())->toBe(0);
});

it('exports the general ledger as PDF in both modes', function (): void {
    app(RecordCollectionAction::class)->execute($this->agent, $this->account, 500);
    $agentCash = app(ChartOfAccounts::class)->agentCash($this->agent);

    $this->actingAs($this->companyAdmin)
        ->get(route('reports.general-ledger.pdf', $this->branch))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->companyAdmin)
        ->get(route('reports.general-ledger.pdf', ['branch' => $this->branch, 'account_id' => $agentCash->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->companyAdmin)
        ->get(route('reports.general-ledger.excel', $this->branch))
        ->assertOk();
});

it('rejects an account from another company on the general ledger export', function (): void {
    $otherBranch = Branch::factory()->create();
    $otherAgent = User::factory()->fieldAgent($otherBranch)->create();
    $foreignAccount = app(ChartOfAccounts::class)->agentCash($otherAgent);

    $this->actingAs($this->companyAdmin)
        ->get(route('reports.general-ledger.pdf', ['branch' => $this->branch, 'account_id' => $foreignAccount->id]))
        ->assertNotFound();
});

it('exports the income statement and balance sheet', function (): void {
    $this->actingAs($this->companyAdmin)
        ->get(route('reports.income-statement.pdf', $this->branch))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->companyAdmin)
        ->get(route('reports.income-statement.excel', $this->branch))
        ->assertOk();

    $this->actingAs($this->companyAdmin)
        ->get(route('reports.balance-sheet.pdf', ['branch' => $this->branch, 'as_at' => now()->toDateString()]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->companyAdmin)
        ->get(route('reports.balance-sheet.excel', $this->branch))
        ->assertOk();
});

it('denies a field agent the financial statements', function (): void {
    $this->actingAs($this->agent)
        ->get(route('reports.income-statement.pdf', $this->branch))
        ->assertForbidden();
});
