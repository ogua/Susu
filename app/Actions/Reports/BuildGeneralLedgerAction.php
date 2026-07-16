<?php

namespace App\Actions\Reports;

use App\Enums\EntryStatus;
use App\Models\Company;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Carbon\CarbonImmutable;

/**
 * The general (nominal) ledger in two modes, mirroring the account-ledger
 * drill-down screen:
 *
 * - Detailed (an account given): every posted line in the period with a
 *   running balance seeded from the opening balance before the period.
 * - Summary (no account): one row per account — activity totals and the
 *   period's net movement, signed by the account's normal balance.
 *
 * @phpstan-type GeneralLedgerDetailedRow array{line: JournalLine, running: int}
 * @phpstan-type GeneralLedgerSummaryRow array{account: LedgerAccount, txn_count: int, debit_total: int, credit_total: int, net: int}
 */
class BuildGeneralLedgerAction
{
    /**
     * @return array<string, mixed>
     */
    public function execute(
        Company $company,
        ?LedgerAccount $account = null,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): array {
        return $account !== null
            ? $this->detailed($account, $from, $to)
            : $this->summary($company, $from, $to);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailed(LedgerAccount $account, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $openingBalance = $from !== null
            ? $this->signedBalanceBefore($account, $from)
            : 0;

        $lines = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_account_id', $account->id)
            ->whereIn('journal_entries.status', [EntryStatus::Completed->value, EntryStatus::Reversed->value])
            ->when($from, fn ($query) => $query->where('journal_entries.recorded_at', '>=', $from->startOfDay()))
            ->when($to, fn ($query) => $query->where('journal_entries.recorded_at', '<=', $to->endOfDay()))
            ->select('journal_lines.*')
            ->with('entry.recordedBy')
            ->orderBy('journal_entries.recorded_at')
            ->orderBy('journal_lines.id')
            ->get();

        $running = $openingBalance;
        $sign = $account->type->normalBalance() === 'debit' ? 1 : -1;

        $rows = $lines->map(function (JournalLine $line) use (&$running, $sign): array {
            $running += $sign * ($line->debit - $line->credit);

            return ['line' => $line, 'running' => $running];
        });

        return [
            'mode' => 'detailed',
            'account' => $account,
            'rows' => $rows,
            'openingBalance' => $openingBalance,
            'closingBalance' => $running,
            'totalDebits' => (int) $lines->sum('debit'),
            'totalCredits' => (int) $lines->sum('credit'),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Company $company, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $inPeriod = function ($query) use ($from, $to): void {
            $query->whereHas('entry', function ($entry) use ($from, $to): void {
                $entry->whereIn('status', [EntryStatus::Completed, EntryStatus::Reversed])
                    ->when($from, fn ($query) => $query->where('recorded_at', '>=', $from->startOfDay()))
                    ->when($to, fn ($query) => $query->where('recorded_at', '<=', $to->endOfDay()));
            });
        };

        $accounts = LedgerAccount::query()
            ->where('company_id', $company->id)
            ->withCount(['lines as txn_count' => $inPeriod])
            ->withSum(['lines as debit_total' => $inPeriod], 'debit')
            ->withSum(['lines as credit_total' => $inPeriod], 'credit')
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        $rows = $accounts->map(fn (LedgerAccount $account): array => [
            'account' => $account,
            'txn_count' => (int) $account->txn_count,
            'debit_total' => (int) $account->debit_total,
            'credit_total' => (int) $account->credit_total,
            'net' => $account->type->normalBalance() === 'debit'
                ? (int) $account->debit_total - (int) $account->credit_total
                : (int) $account->credit_total - (int) $account->debit_total,
        ]);

        return [
            'mode' => 'summary',
            'rows' => $rows,
            'totalDebits' => (int) $rows->sum('debit_total'),
            'totalCredits' => (int) $rows->sum('credit_total'),
            'from' => $from,
            'to' => $to,
        ];
    }

    private function signedBalanceBefore(LedgerAccount $account, CarbonImmutable $date): int
    {
        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_account_id', $account->id)
            ->whereIn('journal_entries.status', [EntryStatus::Completed->value, EntryStatus::Reversed->value])
            ->where('journal_entries.recorded_at', '<', $date->startOfDay())
            ->selectRaw('coalesce(sum(journal_lines.debit),0) as debits, coalesce(sum(journal_lines.credit),0) as credits')
            ->first();

        $debitMinusCredit = (int) $sums->debits - (int) $sums->credits;

        return $account->type->normalBalance() === 'debit' ? $debitMinusCredit : -$debitMinusCredit;
    }
}
