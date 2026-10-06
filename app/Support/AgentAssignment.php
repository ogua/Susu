<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\GroupLoan;
use App\Models\Loan;
use App\Models\LoanGroup;
use App\Models\SavingsAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Which customers a field agent works with. A field agent (without a
 * management role) only sees and collects for customers they are responsible
 * for: assigned to them, or on one of their loans, group loans or savings
 * accounts. Managers and admins are never restricted.
 *
 * Savings deposits keep their own rule (the account's agent) in
 * RecordCollectionAction.
 */
final class AgentAssignment
{
    public static function restricts(User $user): bool
    {
        return $user->hasRole('field_agent')
            && ! $user->hasRole(['super_admin', 'company_admin', 'branch_manager']);
    }

    public static function ownsLoan(User $user, Loan|GroupLoan $loan): bool
    {
        return $loan->agent_id === $user->id || $loan->customer?->assigned_agent_id === $user->id;
    }

    public static function assertMayCollectLoan(User $user, Loan|GroupLoan $loan): void
    {
        if (self::restricts($user) && ! self::ownsLoan($user, $loan)) {
            throw ValidationException::withMessages(['loan' => 'You are not assigned to this loan.']);
        }
    }

    /**
     * Narrow a Customer query to the customers $agent is responsible for.
     *
     * @param  Builder<Customer>  $customers
     * @return Builder<Customer>
     */
    public static function scopeCustomers(Builder $customers, User $agent): Builder
    {
        return $customers->where(fn (Builder $query) => $query
            ->where('assigned_agent_id', $agent->id)
            ->orWhereHas('loans', fn (Builder $loans) => $loans->where('agent_id', $agent->id))
            ->orWhereHas('groupLoans', fn (Builder $loans) => $loans->where('agent_id', $agent->id))
            ->orWhereHas('savingsAccounts', fn (Builder $accounts) => $accounts->where('agent_id', $agent->id)));
    }

    /**
     * Narrow a SavingsAccount query to the accounts $user works with: a field
     * agent's own accounts, or every account in $branchIds for managers and
     * admins (all their accessible branches when null).
     *
     * @param  Builder<SavingsAccount>  $accounts
     * @param  array<int, string>|null  $branchIds
     * @return Builder<SavingsAccount>
     */
    public static function scopeAccounts(Builder $accounts, User $user, ?array $branchIds = null): Builder
    {
        return self::restricts($user)
            ? $accounts->where($accounts->qualifyColumn('agent_id'), $user->id)
            : $accounts->whereIn($accounts->qualifyColumn('branch_id'), $branchIds ?? $user->accessibleBranchIds());
    }

    /**
     * Narrow a LoanGroup query to groups $agent works with: one of their
     * customers is an active member, or they created it (a new, empty group).
     *
     * @param  Builder<LoanGroup>  $groups
     * @return Builder<LoanGroup>
     */
    public static function scopeGroups(Builder $groups, User $agent): Builder
    {
        return $groups->where(fn (Builder $query) => $query
            ->where($query->qualifyColumn('created_by'), $agent->id)
            ->orWhereHas('members', fn (Builder $members) => $members
                ->where('status', 'active')
                ->whereHas('customer', fn (Builder $customer) => self::scopeCustomers($customer, $agent))));
    }

    /**
     * Narrow a Loan or GroupLoan query to loans $agent may collect.
     *
     * @template TModel of Loan|GroupLoan
     *
     * @param  Builder<TModel>  $loans
     * @return Builder<TModel>
     */
    public static function scopeLoans(Builder $loans, User $agent): Builder
    {
        return $loans->where(fn (Builder $query) => $query
            ->where($query->qualifyColumn('agent_id'), $agent->id)
            ->orWhereHas('customer', fn (Builder $customer) => $customer->where('assigned_agent_id', $agent->id)));
    }
}
