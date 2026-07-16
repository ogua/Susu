<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Reports\BuildAgentPerformanceReportAction;
use App\Actions\Reports\BuildBalanceSheetAction;
use App\Actions\Reports\BuildCashPositionAction;
use App\Actions\Reports\BuildCollectionsReportAction;
use App\Actions\Reports\BuildCustomerBalancesAction;
use App\Actions\Reports\BuildDefaultersReportAction;
use App\Actions\Reports\BuildGeneralLedgerAction;
use App\Actions\Reports\BuildGroupReportAction;
use App\Actions\Reports\BuildIncomeStatementAction;
use App\Actions\Reports\BuildLoanPortfolioReportAction;
use App\Actions\Reports\BuildTrialBalanceAction;
use App\Actions\Reports\BuildWithdrawalsReportAction;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\SavingsAccount;
use App\Models\WithdrawalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * JSON report data + short-lived signed download URLs for the mobile and
 * desktop apps — the same Build*Action classes the web report pages use.
 * All amounts are integer minor units.
 */
class ReportController extends Controller
{
    public const REPORTS = [
        'trial-balance',
        'defaulters',
        'cash-position',
        'collections',
        'loan-portfolio',
        'agent-performance',
        'withdrawals',
        'groups',
        'customer-balances',
        'general-ledger',
        'income-statement',
        'balance-sheet',
    ];

    public function show(Request $request, string $report): JsonResponse
    {
        abort_unless(in_array($report, self::REPORTS, true), 404);

        $branch = $this->resolveBranch($request);
        [$from, $to] = $this->dateRange($request);

        $data = match ($report) {
            'trial-balance' => $this->trialBalance($branch),
            'defaulters' => $this->defaulters($branch),
            'cash-position' => $this->cashPosition($branch),
            'collections' => $this->collections($branch, $from, $to),
            'loan-portfolio' => $this->loanPortfolio($branch, $from, $to),
            'agent-performance' => $this->agentPerformance($branch, $from, $to),
            'withdrawals' => $this->withdrawals($branch, $from, $to),
            'groups' => $this->groups($branch, $from, $to),
            'customer-balances' => $this->customerBalances($branch),
            'general-ledger' => $this->generalLedger($request, $branch, $from, $to),
            'income-statement' => $this->incomeStatement($branch, $from, $to),
            'balance-sheet' => $this->balanceSheet($request, $branch),
        };

        return response()->json([
            'data' => $data,
            'meta' => [
                'report' => $report,
                'branch_id' => $branch->id,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Mints a short-lived signed URL onto the web report routes so a client
     * can hand the PDF/Excel to a browser without carrying its API token.
     */
    public function downloadUrl(Request $request, string $report): JsonResponse
    {
        abort_unless(in_array($report, self::REPORTS, true), 404);

        $branch = $this->resolveBranch($request);

        $validated = $request->validate([
            'format' => ['required', 'in:pdf,xlsx'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'string'],
            'account_id' => ['nullable', 'uuid'],
            'as_at' => ['nullable', 'date'],
        ]);

        $expiresAt = now()->addMinutes(5);

        $url = URL::temporarySignedRoute('reports.signed', $expiresAt, array_filter([
            'branch' => $branch->id,
            'report' => $report,
            'format' => $validated['format'],
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'status' => $validated['status'] ?? null,
            'account_id' => $validated['account_id'] ?? null,
            'as_at' => $validated['as_at'] ?? null,
        ]));

        return response()->json([
            'url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    private function resolveBranch(Request $request): Branch
    {
        $user = $request->user();

        $validated = $request->validate([
            'branch_id' => ['nullable', 'uuid'],
        ]);

        if (isset($validated['branch_id']) && $user->hasRole('company_admin')) {
            return Branch::query()
                ->where('company_id', $user->company_id)
                ->findOrFail($validated['branch_id']);
        }

        return Branch::findOrFail($user->branch_id);
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function dateRange(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return [
            isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : null,
            isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trialBalance(Branch $branch): array
    {
        $result = app(BuildTrialBalanceAction::class)->execute($branch->company);

        return [
            'accounts' => $result['accounts']->map(fn (LedgerAccount $account): array => [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'normal_balance' => $account->type->normalBalance(),
                'balance' => (int) $account->balance,
            ])->all(),
            'total_debits' => $result['totalDebits'],
            'total_credits' => $result['totalCredits'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaulters(Branch $branch): array
    {
        $installments = app(BuildDefaultersReportAction::class)->execute($branch);

        return [
            'installments' => $installments->map(fn (LoanInstallment $installment): array => [
                'loan_number' => $installment->loan->loan_number,
                'customer' => $installment->loan->customer->fullName(),
                'phone' => $installment->loan->customer->phone,
                'agent' => $installment->loan->agent?->name,
                'due_date' => $installment->due_date->toDateString(),
                'days_overdue' => (int) $installment->due_date->diffInDays(now()),
                'amount_due' => $installment->remaining(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cashPosition(Branch $branch): array
    {
        $result = app(BuildCashPositionAction::class)->execute($branch);

        return [
            'accounts' => $result['accounts']->map(fn (LedgerAccount $account): array => [
                'code' => $account->code,
                'name' => $account->name,
                'balance' => (int) $account->balance,
            ])->all(),
            'total' => (int) $result['total'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collections(Branch $branch, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $result = app(BuildCollectionsReportAction::class)->execute($branch, $from, $to);

        return [
            'entries' => $result['entries']->map(fn (JournalEntry $entry): array => [
                'recorded_at' => $entry->recorded_at->toIso8601String(),
                'reference' => $entry->reference,
                'agent' => $entry->recordedBy?->name,
                'description' => $entry->description,
                'payment_method' => $entry->payment_method->value,
                'status' => $entry->status->value,
                'amount' => (int) $entry->amount_sum,
            ])->all(),
            'agent_subtotals' => $result['agentSubtotals']->all(),
            'total_amount' => $result['totalAmount'],
            'total_count' => $result['totalCount'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loanPortfolio(Branch $branch, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $result = app(BuildLoanPortfolioReportAction::class)->execute($branch, $from, $to);

        return [
            'loans' => $result['loans']->map(fn (Loan $loan): array => [
                'loan_number' => $loan->loan_number,
                'customer' => $loan->customer->fullName(),
                'agent' => $loan->agent?->name,
                'product' => $loan->loanProduct?->name,
                'status' => $loan->status->value,
                'applied_at' => $loan->applied_at?->toDateString(),
                'disbursed_at' => $loan->disbursed_at?->toDateString(),
                'principal' => (int) $loan->principal_amount,
                'repayable' => (int) ($loan->total_repayable ?? 0),
                'outstanding' => (int) ($loan->outstanding_balance ?? 0),
            ])->all(),
            'status_summary' => $result['statusSummary']->map(fn (array $row): array => [
                'status' => $row['status']->value,
                'count' => $row['count'],
                'principal' => $row['principal'],
                'outstanding' => $row['outstanding'],
            ])->all(),
            'total_principal' => $result['totalPrincipal'],
            'total_outstanding' => $result['totalOutstanding'],
            'at_risk' => $result['atRiskOutstanding'],
            'par_percent' => $result['parPercent'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function agentPerformance(Branch $branch, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $result = app(BuildAgentPerformanceReportAction::class)->execute($branch, $from, $to);

        return [
            'rows' => $result['rows']->all(),
            'totals' => $result['totals'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function withdrawals(Branch $branch, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $result = app(BuildWithdrawalsReportAction::class)->execute($branch, $from, $to);

        return [
            'requests' => $result['requests']->map(fn (WithdrawalRequest $withdrawal): array => [
                'requested_at' => $withdrawal->created_at->toIso8601String(),
                'account_number' => $withdrawal->savingsAccount?->account_number,
                'customer' => $withdrawal->customer?->fullName(),
                'status' => $withdrawal->status->value,
                'requested_by' => $withdrawal->requestedBy?->name,
                'approved_by' => $withdrawal->approvedBy?->name,
                'penalty' => (int) ($withdrawal->penalty_amount ?? 0),
                'amount' => (int) $withdrawal->amount,
            ])->all(),
            'status_totals' => $result['statusTotals']->map(fn (array $row): array => [
                'status' => $row['status']->value,
                'count' => $row['count'],
                'amount' => $row['amount'],
                'penalty' => $row['penalty'],
            ])->all(),
            'total_amount' => $result['totalAmount'],
            'total_penalty' => $result['totalPenalty'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groups(Branch $branch, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $result = app(BuildGroupReportAction::class)->execute($branch, $from, $to);

        return [
            'rows' => $result['rows']->map(fn (array $row): array => [
                'name' => $row['group']->name,
                'code' => $row['group']->code,
                'status' => $row['group']->status->value,
                'members_count' => $row['members_count'],
                'current_round' => $row['current_round'],
                'round_expected' => $row['round_expected'],
                'round_collected' => $row['round_collected'],
                'rounds_paid_out' => $row['rounds_paid_out'],
                'collected_in_period' => $row['collected_in_period'],
                'lifetime_collected' => $row['lifetime_collected'],
            ])->all(),
            'total_collected_in_period' => $result['totalCollectedInPeriod'],
            'total_lifetime_collected' => $result['totalLifetimeCollected'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerBalances(Branch $branch): array
    {
        $result = app(BuildCustomerBalancesAction::class)->execute($branch);

        return [
            'accounts' => $result['accounts']->map(fn (SavingsAccount $account): array => [
                'account_number' => $account->account_number,
                'customer' => $account->customer->fullName(),
                'phone' => $account->customer->phone,
                'product' => $account->product?->name,
                'agent' => $account->agent?->name,
                'status' => $account->status->value,
                'balance' => (int) $account->balance,
            ])->all(),
            'total_balance' => $result['totalBalance'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generalLedger(Request $request, Branch $branch, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $validated = $request->validate([
            'account_id' => ['nullable', 'uuid'],
        ]);

        $account = null;
        if (isset($validated['account_id'])) {
            $account = LedgerAccount::query()
                ->where('company_id', $branch->company_id)
                ->findOrFail($validated['account_id']);
        }

        $result = app(BuildGeneralLedgerAction::class)->execute($branch->company, $account, $from, $to);

        if ($result['mode'] === 'detailed') {
            return [
                'mode' => 'detailed',
                'account' => [
                    'id' => $result['account']->id,
                    'code' => $result['account']->code,
                    'name' => $result['account']->name,
                    'type' => $result['account']->type->value,
                ],
                'opening_balance' => $result['openingBalance'],
                'closing_balance' => $result['closingBalance'],
                'total_debits' => $result['totalDebits'],
                'total_credits' => $result['totalCredits'],
                'rows' => $result['rows']->map(fn (array $row): array => [
                    'recorded_at' => $row['line']->entry->recorded_at->toIso8601String(),
                    'reference' => $row['line']->entry->reference,
                    'type' => $row['line']->entry->type->value,
                    'description' => $row['line']->memo ?? $row['line']->entry->description,
                    'debit' => (int) $row['line']->debit,
                    'credit' => (int) $row['line']->credit,
                    'running_balance' => $row['running'],
                ])->all(),
            ];
        }

        return [
            'mode' => 'summary',
            'total_debits' => $result['totalDebits'],
            'total_credits' => $result['totalCredits'],
            'rows' => $result['rows']->map(fn (array $row): array => [
                'account_id' => $row['account']->id,
                'code' => $row['account']->code,
                'name' => $row['account']->name,
                'type' => $row['account']->type->value,
                'txn_count' => $row['txn_count'],
                'debit_total' => $row['debit_total'],
                'credit_total' => $row['credit_total'],
                'net' => $row['net'],
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function incomeStatement(Branch $branch, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $result = app(BuildIncomeStatementAction::class)->execute($branch->company, $from, $to);

        return [
            'income_rows' => $result['incomeRows']->all(),
            'expense_rows' => $result['expenseRows']->all(),
            'total_income' => $result['totalIncome'],
            'total_expenses' => $result['totalExpenses'],
            'net_income' => $result['netIncome'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceSheet(Request $request, Branch $branch): array
    {
        $validated = $request->validate([
            'as_at' => ['nullable', 'date'],
        ]);

        $result = app(BuildBalanceSheetAction::class)->execute(
            $branch->company,
            isset($validated['as_at']) ? CarbonImmutable::parse($validated['as_at']) : null,
        );

        return [
            'asset_rows' => $result['assetRows']->all(),
            'liability_rows' => $result['liabilityRows']->all(),
            'equity_rows' => $result['equityRows']->all(),
            'total_assets' => $result['totalAssets'],
            'total_liabilities' => $result['totalLiabilities'],
            'total_equity' => $result['totalEquity'],
            'retained_earnings' => $result['retainedEarnings'],
            'is_balanced' => $result['isBalanced'],
            'as_at' => $result['asAt']->toDateString(),
        ];
    }
}
