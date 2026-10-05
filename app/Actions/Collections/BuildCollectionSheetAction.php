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
use App\Models\SavingsAccount;
use App\Models\User;
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
 *   the date, optionally narrowed to one loan officer.
 *
 * "Due" is everything scheduled up to and including the date that hasn't been
 * paid (arrears + today), capped at the loan's outstanding balance — the
 * default amount the sheet pre-fills.
 */
class BuildCollectionSheetAction
{
    /**
     * @return list<array{key: string, customer_id: string, customer_name: string, customer_code: ?string, phone: ?string, loan_type: ?string, loan_id: ?string, loan_number: ?string, product: ?string, outstanding: int, amount_due: int, overdue: int, savings_account_id: ?string, savings_account_number: ?string, savings_balance: ?int, contribution_amount: ?int}>
     */
    public function execute(Branch $branch, CarbonInterface $date, ?LoanGroup $loanGroup = null, ?User $officer = null): array
    {
        $rows = $loanGroup !== null
            ? $this->groupRows($loanGroup, $date)
            : $this->dueLoanRows($branch, $date, $officer);

        return $rows->sortBy('customer_name')->values()->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function groupRows(LoanGroup $loanGroup, CarbonInterface $date): Collection
    {
        $members = $loanGroup->members()->where('status', 'active')->with('customer')->get();
        $loans = $loanGroup->groupLoans()
            ->where('status', GroupLoanStatus::Active)
            ->with('installments')
            ->get()
            ->keyBy('loan_group_member_id');
        $savings = $this->savingsAccountsFor($members->pluck('customer_id'));

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
        $officerScope = fn (Builder $query) => $query->where(fn (Builder $either) => $either
            ->where('agent_id', $officer?->id)
            ->orWhereHas('customer', fn (Builder $customer) => $customer->where('assigned_agent_id', $officer?->id)));

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

        $savings = $this->savingsAccountsFor($groupLoans->pluck('customer_id')->merge($loans->pluck('customer_id')));

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
     * daily-susu account, else their oldest active target account.
     *
     * @param  Collection<int, string>  $customerIds
     * @return Collection<string, SavingsAccount>
     */
    private function savingsAccountsFor(Collection $customerIds): Collection
    {
        return SavingsAccount::whereIn('customer_id', $customerIds->unique()->values())
            ->where('status', AccountStatus::Active)
            ->whereHas('product', fn (Builder $product) => $product->whereIn('type', [SavingsProductType::DailySusu, SavingsProductType::Target]))
            ->with('product')
            ->orderBy('opened_at')
            ->get()
            ->sortBy(fn (SavingsAccount $account): int => $account->product->type === SavingsProductType::DailySusu ? 0 : 1)
            ->unique('customer_id')
            ->keyBy('customer_id');
    }
}
