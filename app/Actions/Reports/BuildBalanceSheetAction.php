<?php

namespace App\Actions\Reports;

use App\Enums\EntryStatus;
use App\Enums\LedgerAccountType;
use App\Models\Company;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Statement of financial position as at a date, computed from posted journal
 * lines up to that date. Retained earnings (all income − all expenses to
 * date) bridges the books: assets = liabilities + equity + retained earnings.
 *
 * @phpstan-type SheetRow array{code: string, name: string, amount: int}
 * @phpstan-type BalanceSheetResult array{
 *     assetRows: Collection<int, SheetRow>,
 *     liabilityRows: Collection<int, SheetRow>,
 *     equityRows: Collection<int, SheetRow>,
 *     totalAssets: int,
 *     totalLiabilities: int,
 *     totalEquity: int,
 *     retainedEarnings: int,
 *     isBalanced: bool,
 *     asAt: CarbonImmutable,
 * }
 */
class BuildBalanceSheetAction
{
    /**
     * @return BalanceSheetResult
     */
    public function execute(Company $company, ?CarbonImmutable $asAt = null): array
    {
        $asAt ??= CarbonImmutable::now();

        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('ledger_accounts.company_id', $company->id)
            ->whereIn('journal_entries.status', [EntryStatus::Completed->value, EntryStatus::Reversed->value])
            ->where('journal_entries.recorded_at', '<=', $asAt->endOfDay())
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

        $rowsFor = function (LedgerAccountType $type) use ($sums): Collection {
            $sign = $type->normalBalance() === 'debit' ? 1 : -1;

            return $sums
                ->where('account_type', $type->value)
                ->map(fn ($row): array => [
                    'code' => $row->code,
                    'name' => $row->name,
                    'amount' => $sign * ((int) $row->debits - (int) $row->credits),
                ])
                ->filter(fn (array $row): bool => $row['amount'] !== 0)
                ->values();
        };

        $assetRows = $rowsFor(LedgerAccountType::Asset);
        $liabilityRows = $rowsFor(LedgerAccountType::Liability);
        $equityRows = $rowsFor(LedgerAccountType::Equity);

        $retainedEarnings = (int) $rowsFor(LedgerAccountType::Income)->sum('amount')
            - (int) $rowsFor(LedgerAccountType::Expense)->sum('amount');

        $totalAssets = (int) $assetRows->sum('amount');
        $totalLiabilities = (int) $liabilityRows->sum('amount');
        $totalEquity = (int) $equityRows->sum('amount');

        return [
            'assetRows' => $assetRows,
            'liabilityRows' => $liabilityRows,
            'equityRows' => $equityRows,
            'totalAssets' => $totalAssets,
            'totalLiabilities' => $totalLiabilities,
            'totalEquity' => $totalEquity,
            'retainedEarnings' => $retainedEarnings,
            'isBalanced' => $totalAssets === $totalLiabilities + $totalEquity + $retainedEarnings,
            'asAt' => $asAt,
        ];
    }
}
