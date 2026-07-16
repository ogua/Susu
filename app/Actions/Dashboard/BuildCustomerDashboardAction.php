<?php

namespace App\Actions\Dashboard;

use App\Enums\AccountStatus;
use App\Enums\EntryStatus;
use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\Loan;
use App\Models\SavingsAccount;
use Carbon\CarbonImmutable;

/**
 * A customer's own dashboard: accounts with balances, a 30-day total-balance
 * trend (rebuilt from daily net movement on their savings ledger accounts),
 * and a loan summary. Powers /api/v1/dashboard/customer (the mobile customer
 * home screen). All amounts are integer minor units.
 */
class BuildCustomerDashboardAction
{
    private const TREND_DAYS = 30;

    /**
     * @return array<string, mixed>
     */
    public function execute(Customer $customer): array
    {
        $accounts = SavingsAccount::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [AccountStatus::Active, AccountStatus::Dormant])
            ->with('product')
            ->orderBy('account_number')
            ->get();

        $totalBalance = (int) $accounts->sum('balance');

        return [
            'accounts' => $accounts->map(fn (SavingsAccount $account): array => [
                'id' => $account->id,
                'account_number' => $account->account_number,
                'product' => $account->product?->name,
                'balance' => (int) $account->balance,
                'status' => $account->status->value,
                'target_progress_percent' => $account->targetProgressPercent(),
            ])->all(),
            'totals' => ['balance' => $totalBalance],
            'balance_trend' => $this->balanceTrend($accounts->pluck('ledger_account_id')->filter()->all(), $totalBalance),
            'loans' => $this->loanSummary($customer),
        ];
    }

    /**
     * Walks the last 30 days of net savings movement forward from the balance
     * the customer had at the start of the window, ending at today's total.
     *
     * @param  array<int, string>  $ledgerAccountIds
     * @return array<int, array{date: string, balance: int}>
     */
    private function balanceTrend(array $ledgerAccountIds, int $currentTotal): array
    {
        $start = CarbonImmutable::today()->subDays(self::TREND_DAYS - 1);

        if ($ledgerAccountIds === []) {
            return [];
        }

        // Savings accounts are liability accounts: credits grow the balance.
        $netByDate = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.ledger_account_id', $ledgerAccountIds)
            ->whereIn('journal_entries.status', [EntryStatus::Completed->value, EntryStatus::Reversed->value])
            ->where('journal_entries.recorded_at', '>=', $start->startOfDay())
            ->selectRaw('journal_entries.recorded_at as recorded_at, journal_lines.credit - journal_lines.debit as net')
            ->get()
            ->groupBy(fn ($row): string => CarbonImmutable::parse($row->recorded_at)->toDateString())
            ->map(fn ($group): int => (int) $group->sum('net'));

        $running = $currentTotal - (int) $netByDate->sum();

        return collect(range(0, self::TREND_DAYS - 1))
            ->map(function (int $offset) use ($start, $netByDate, &$running): array {
                $date = $start->addDays($offset)->toDateString();
                $running += (int) ($netByDate[$date] ?? 0);

                return ['date' => $date, 'balance' => $running];
            })
            ->all();
    }

    /**
     * @return array{active_count: int, outstanding: int}
     */
    private function loanSummary(Customer $customer): array
    {
        $active = Loan::query()
            ->where('customer_id', $customer->id)
            ->where('status', LoanStatus::Disbursed);

        return [
            'active_count' => (clone $active)->count(),
            'outstanding' => (int) (clone $active)->sum('outstanding_balance'),
        ];
    }
}
