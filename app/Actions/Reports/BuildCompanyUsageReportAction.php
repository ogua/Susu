<?php

namespace App\Actions\Reports;

use App\Enums\AccountStatus;
use App\Enums\EntryStatus;
use App\Enums\LoanStatus;
use App\Enums\TransactionType;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Platform-operator view of every tenant: size (branches, staff, customers),
 * book (savings held, loans outstanding), activity in the period (new
 * customers, net collections) and health (active company admins, last
 * transaction). Collections count only completed entries, so a reversed
 * collection drops out rather than being double-counted against its reversal.
 * The period defaults to month-to-date.
 *
 * Each Company comes back with: branches_count, staff_count,
 * company_admins_count, customers_count, new_customers_count,
 * active_savings_accounts_count, savings_balance, loans_outstanding,
 * collections_count, collections_amount, last_activity_at.
 */
class BuildCompanyUsageReportAction
{
    /**
     * @return Collection<int, Company>
     */
    public function execute(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Collection
    {
        return $this->query($from, $to)->orderBy('name')->get();
    }

    /**
     * @return Builder<Company>
     */
    public function query(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Builder
    {
        $from = ($from ?? CarbonImmutable::now()->startOfMonth())->startOfDay();
        $to = ($to ?? CarbonImmutable::now())->endOfDay();

        return Company::query()
            ->select('companies.*')
            ->withCount([
                'branches',
                'users as staff_count' => fn (Builder $query) => $query
                    ->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('name', 'customer')),
                'activeCompanyAdmins as company_admins_count',
                'customers',
                'customers as new_customers_count' => fn (Builder $query) => $query
                    ->whereBetween('created_at', [$from, $to]),
                'savingsAccounts as active_savings_accounts_count' => fn (Builder $query) => $query
                    ->where('status', AccountStatus::Active),
                'journalEntries as collections_count' => fn (Builder $query) => $this->completedCollections($query, $from, $to),
            ])
            ->withSum('savingsAccounts as savings_balance', 'balance')
            ->withSum(['loans as loans_outstanding' => fn (Builder $query) => $query
                ->where('status', LoanStatus::Disbursed)], 'outstanding_balance')
            ->withMax('journalEntries as last_activity_at', 'recorded_at')
            ->addSelect([
                'collections_amount' => JournalLine::query()
                    ->selectRaw('coalesce(sum(journal_lines.debit), 0)')
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->whereColumn('journal_entries.company_id', 'companies.id')
                    ->where('journal_entries.type', TransactionType::Collection)
                    ->where('journal_entries.status', EntryStatus::Completed)
                    ->whereBetween('journal_entries.recorded_at', [$from, $to]),
            ]);
    }

    /**
     * @param  Builder<JournalEntry>  $query
     * @return Builder<JournalEntry>
     */
    private function completedCollections(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query
            ->where('type', TransactionType::Collection)
            ->where('status', EntryStatus::Completed)
            ->whereBetween('recorded_at', [$from, $to]);
    }
}
