<?php

namespace App\Actions\Collections;

use App\Enums\AccountStatus;
use App\Enums\GroupLoanStatus;
use App\Enums\LoanStatus;
use App\Enums\SavingsProductType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\Loan;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Support\AgentAssignment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The collection sheet ("Enter Transaction"): who is expected to pay on a date.
 *
 * - With a group: every active member of that group, one row each, carrying
 *   their active loan in the group (if any) and their savings account — so
 *   members who only save still get a row.
 * - Without a group: every loan in the branch with something due on or before
 *   the date.
 * - All customers: every active customer, one row per open loan (due or not)
 *   or a savings-only row — for taking a payment from someone not due today.
 *   Capped at CUSTOMER_LIMIT; search to narrow.
 *
 * Every row carries the customer's group name(s). A search (name, phone,
 * customer code, loan or account number, group) filters any mode.
 *
 * With an officer (always the case for field agents), both modes narrow to
 * that officer's customers (AgentAssignment): their loans and the savings
 * accounts they collect, so the sheet never offers an entry the server would
 * then refuse.
 *
 * "Due" is everything scheduled up to and including the date that hasn't been
 * paid (arrears + today), capped at the loan's outstanding balance — the
 * default amount the sheet pre-fills.
 */
class BuildCollectionSheetAction
{
    public const CUSTOMER_LIMIT = 200;

    /**
     * @return list<array{key: string, customer_id: string, customer_name: string, customer_code: ?string, phone: ?string, group_name: ?string, loan_type: ?string, loan_id: ?string, loan_number: ?string, product: ?string, outstanding: int, amount_due: int, overdue: int, savings_account_id: ?string, savings_account_number: ?string, savings_balance: ?int, contribution_amount: ?int}>
     */
    public function execute(
        Branch $branch,
        CarbonInterface $date,
        ?LoanGroup $loanGroup = null,
        ?User $officer = null,
        bool $allCustomers = false,
        ?string $search = null,
    ): array {
        $terms = self::searchTerms($search);

        $rows = match (true) {
            $loanGroup !== null => $this->groupRows($loanGroup, $date, $officer),
            $allCustomers => $this->customerRows($branch, $date, $officer, $terms),
            default => $this->dueLoanRows($branch, $date, $officer),
        };

        $groupNames = $this->groupNamesFor($rows->pluck('customer_id'));
        $rows = $rows->map(fn (array $row): array => $row + ['group_name' => $groupNames->get($row['customer_id'])]);

        if ($terms !== []) {
            $rows = $rows->filter(fn (array $row): bool => self::matches($row, $terms));
        }

        return $rows->sortBy('customer_name')->values()->all();
    }

    /**
     * Every active customer in the branch (the officer's only, with one), with
     * each open loan they may be collected on, or a savings-only row.
     *
     * @param  list<string>  $terms
     * @return Collection<int, array<string, mixed>>
     */
    private function customerRows(Branch $branch, CarbonInterface $date, ?User $officer, array $terms): Collection
    {
        $customers = Customer::where('branch_id', $branch->id)
            ->where('status', AccountStatus::Active)
            ->when($officer, fn (Builder $query) => AgentAssignment::scopeCustomers($query, $officer))
            ->when($terms !== [], function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $like = '%'.$term.'%';
                    $query->where(fn (Builder $any) => $any
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('business_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('customer_code', 'like', $like)
                        ->orWhereHas('savingsAccounts', fn (Builder $account) => $account->where('account_number', 'like', $like))
                        ->orWhereHas('loans', fn (Builder $loan) => $loan->where('loan_number', 'like', $like))
                        ->orWhereHas('groupLoans', fn (Builder $loan) => $loan->where('loan_number', 'like', $like)));
                }
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(self::CUSTOMER_LIMIT)
            ->get();
        $ids = $customers->pluck('id');

        $loans = Loan::whereIn('customer_id', $ids)
            ->where('status', LoanStatus::Disbursed)
            ->when($officer, fn (Builder $query) => AgentAssignment::scopeLoans($query, $officer))
            ->with(['installments', 'loanProduct'])
            ->get();
        $groupLoans = GroupLoan::whereIn('customer_id', $ids)
            ->where('status', GroupLoanStatus::Active)
            ->when($officer, fn (Builder $query) => AgentAssignment::scopeLoans($query, $officer))
            ->with('installments')
            ->get();
        $openLoans = $groupLoans->concat($loans)->groupBy('customer_id');
        $savings = $this->savingsAccountsFor($ids, $officer);

        return $customers->flatMap(function (Customer $customer) use ($openLoans, $savings, $date): array {
            $account = $savings->get($customer->id);
            $own = $openLoans->get($customer->id, collect());

            return $own->isEmpty()
                ? [$this->row($customer, null, $account, $date)]
                : $own->map(fn (GroupLoan|Loan $loan): array => $this->row($customer, $loan, $account, $date))->all();
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function groupRows(LoanGroup $loanGroup, CarbonInterface $date, ?User $officer): Collection
    {
        $members = $loanGroup->members()
            ->where('status', 'active')
            ->when($officer, fn (Builder $query) => $query->whereHas('customer', fn (Builder $customer) => AgentAssignment::scopeCustomers($customer, $officer)))
            ->with('customer')
            ->get();
        $loans = $loanGroup->groupLoans()
            ->where('status', GroupLoanStatus::Active)
            ->when($officer, fn (Builder $query) => AgentAssignment::scopeLoans($query, $officer))
            ->with('installments')
            ->get()
            ->keyBy('loan_group_member_id');
        $savings = $this->savingsAccountsFor($members->pluck('customer_id'), $officer);

        return $members->map(fn ($member): array => $this->row(
            $member->customer,
            $loans->get($member->id),
            $savings->get($member->customer_id),
            $date,
        ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function dueLoanRows(Branch $branch, CarbonInterface $date, ?User $officer): Collection
    {
        $officerScope = fn (Builder $query) => AgentAssignment::scopeLoans($query, $officer);

        $groupLoans = GroupLoan::where('branch_id', $branch->id)
            ->where('status', GroupLoanStatus::Active)
            ->whereHas('installments', fn (Builder $installments) => $installments
                ->whereDate('due_date', '<=', $date->toDateString())
                ->whereColumn('amount_paid', '<', 'amount_due'))
            ->when($officer, $officerScope)
            ->with(['customer', 'installments'])
            ->get();

        $loans = Loan::where('branch_id', $branch->id)
            ->where('status', LoanStatus::Disbursed)
            ->whereHas('installments', fn (Builder $installments) => $installments
                ->whereDate('due_date', '<=', $date->toDateString())
                ->whereRaw('principal_paid + interest_paid + penalty_paid < principal_due + interest_due + penalty_due'))
            ->when($officer, $officerScope)
            ->with(['customer', 'installments', 'loanProduct'])
            ->get();

        $savings = $this->savingsAccountsFor($groupLoans->pluck('customer_id')->merge($loans->pluck('customer_id')), $officer);

        return $groupLoans->map(fn (GroupLoan $loan): array => $this->row($loan->customer, $loan, $savings->get($loan->customer_id), $date))
            ->merge($loans->map(fn (Loan $loan): array => $this->row($loan->customer, $loan, $savings->get($loan->customer_id), $date)));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Customer $customer, GroupLoan|Loan|null $loan, ?SavingsAccount $account, CarbonInterface $date): array
    {
        [$due, $overdue] = $loan === null ? [0, 0] : $this->dueAmounts($loan, $date);

        return [
            'key' => $customer->id.':'.($loan?->id ?? 'savings'),
            'customer_id' => $customer->id,
            'customer_name' => $customer->fullName() ?: (string) $customer->business_name,
            'customer_code' => $customer->customer_code,
            'phone' => $customer->phone,
            'loan_type' => match (true) {
                $loan instanceof GroupLoan => 'group',
                $loan instanceof Loan => 'individual',
                default => null,
            },
            'loan_id' => $loan?->id,
            'loan_number' => $loan?->loan_number,
            'product' => match (true) {
                $loan instanceof GroupLoan => 'Group loan',
                $loan instanceof Loan => $loan->loanProduct?->name,
                default => null,
            },
            'outstanding' => (int) ($loan?->outstanding_balance ?? 0),
            'amount_due' => $due,
            'overdue' => $overdue,
            'savings_account_id' => $account?->id,
            'savings_account_number' => $account?->account_number,
            'savings_balance' => $account?->balance,
            'contribution_amount' => $account?->contribution_amount,
        ];
    }

    /**
     * @return list<string>
     */
    public static function searchTerms(?string $search): array
    {
        return array_values(array_filter(
            preg_split('/\s+/', mb_strtolower(trim((string) $search))) ?: [],
            fn (string $term): bool => $term !== '',
        ));
    }

    /**
     * Every term must appear somewhere in the row (so "ama 024" finds Ama by phone).
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $terms
     */
    public static function matches(array $row, array $terms): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $row['customer_name'], $row['customer_code'], $row['phone'], $row['group_name'],
            $row['loan_number'], $row['savings_account_number'],
        ])));

        foreach ($terms as $term) {
            if (! str_contains($haystack, $term)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The active group(s) each customer belongs to, comma-separated.
     *
     * @param  Collection<int, string>  $customerIds
     * @return Collection<string, string>
     */
    private function groupNamesFor(Collection $customerIds): Collection
    {
        return LoanGroupMember::whereIn('customer_id', $customerIds->unique()->values())
            ->where('status', 'active')
            ->with('loanGroup:id,name')
            ->get()
            ->groupBy('customer_id')
            ->map(fn (Collection $members): string => $members->pluck('loanGroup.name')->filter()->unique()->sort()->implode(', '));
    }

    /**
     * @return array{0: int, 1: int} [due up to and including the date, of which overdue before the date]
     */
    private function dueAmounts(GroupLoan|Loan $loan, CarbonInterface $date): array
    {
        $due = 0;
        $overdue = 0;

        foreach ($loan->installments as $installment) {
            if ($installment->due_date->gt($date->copy()->endOfDay())) {
                continue;
            }

            $remaining = $loan instanceof GroupLoan
                ? $installment->amount_due - $installment->amount_paid
                : ($installment->principal_due + $installment->interest_due + $installment->penalty_due)
                    - ($installment->principal_paid + $installment->interest_paid + $installment->penalty_paid);
            $remaining = max(0, $remaining);

            $due += $remaining;
            if ($installment->due_date->lt($date->copy()->startOfDay())) {
                $overdue += $remaining;
            }
        }

        return [min($due, (int) $loan->outstanding_balance), min($overdue, (int) $loan->outstanding_balance)];
    }

    /**
     * One account per customer to take sheet deposits: their oldest active
     * daily-susu account, else their oldest active target account. With an
     * officer, only accounts that officer collects (RecordCollectionAction).
     *
     * @param  Collection<int, string>  $customerIds
     * @return Collection<string, SavingsAccount>
     */
    private function savingsAccountsFor(Collection $customerIds, ?User $officer = null): Collection
    {
        return SavingsAccount::whereIn('customer_id', $customerIds->unique()->values())
            ->where('status', AccountStatus::Active)
            ->when($officer, fn (Builder $query) => $query->where('agent_id', $officer->id))
            ->whereHas('product', fn (Builder $product) => $product->whereIn('type', [SavingsProductType::DailySusu, SavingsProductType::Target]))
            ->with('product')
            ->orderBy('opened_at')
            ->get()
            ->sortBy(fn (SavingsAccount $account): int => $account->product->type === SavingsProductType::DailySusu ? 0 : 1)
            ->unique('customer_id')
            ->keyBy('customer_id');
    }
}
