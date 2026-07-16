<?php

namespace App\Actions\Reports;

use App\Enums\EntryStatus;
use App\Enums\LedgerAccountType;
use App\Models\Company;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Profit & loss over a period, computed from posted journal lines rather than
 * cached balances so a date range works: income accounts read credits−debits,
 * expense accounts debits−credits, net income is the difference.
 *
 * @phpstan-type StatementRow array{code: string, name: string, amount: int}
 * @phpstan-type IncomeStatementResult array{
 *     incomeRows: Collection<int, StatementRow>,
 *     expenseRows: Collection<int, StatementRow>,
 *     totalIncome: int,
 *     totalExpenses: int,
 *     netIncome: int,
 *     from: ?CarbonImmutable,
 *     to: ?CarbonImmutable,
 * }
 */
class BuildIncomeStatementAction
{
    /**
     * @return IncomeStatementResult
     */
    public function execute(Company $company, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('ledger_accounts.company_id', $company->id)
            ->whereIn('ledger_accounts.type', [LedgerAccountType::Income->value, LedgerAccountType::Expense->value])
            ->whereIn('journal_entries.status', [EntryStatus::Completed->value, EntryStatus::Reversed->value])
            ->when($from, fn ($query) => $query->where('journal_entries.recorded_at', '>=', $from->startOfDay()))
            ->when($to, fn ($query) => $query->where('journal_entries.recorded_at', '<=', $to->endOfDay()))
            ->groupBy('ledger_accounts.id', 'ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.type')
            ->selectRaw(<<<'SQL'
                ledger_accounts.code as code,
                ledger_accounts.name as name,
                ledger_accounts.type as account_type,
                coalesce(sum(journal_lines.debit), 0) as debits,
                coalesce(sum(journal_lines.credit), 0) as credits
                SQL)
            ->orderBy('ledger_accounts.code')
            ->get();

        $incomeRows = $sums
            ->where('account_type', LedgerAccountType::Income->value)
            ->map(fn ($row): array => [
                'code' => $row->code,
                'name' => $row->name,
                'amount' => (int) $row->credits - (int) $row->debits,
            ])
            ->filter(fn (array $row): bool => $row['amount'] !== 0)
            ->values();

        $expenseRows = $sums
            ->where('account_type', LedgerAccountType::Expense->value)
            ->map(fn ($row): array => [
                'code' => $row->code,
                'name' => $row->name,
                'amount' => (int) $row->debits - (int) $row->credits,
            ])
            ->filter(fn (array $row): bool => $row['amount'] !== 0)
            ->values();

        $totalIncome = (int) $incomeRows->sum('amount');
        $totalExpenses = (int) $expenseRows->sum('amount');

        return [
            'incomeRows' => $incomeRows,
            'expenseRows' => $expenseRows,
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'netIncome' => $totalIncome - $totalExpenses,
            'from' => $from,
            'to' => $to,
        ];
    }
}
