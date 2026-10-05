<?php

namespace App\Enums;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * The customer-list tabs. Defined once so the web tabs, the stats header and
 * GET /api/v1/customers?segment= all count customers the same way.
 *
 * "Pending" = registered but no savings account opened yet (onboarding not
 * finished). There is no customer-approval workflow, so this is the only
 * meaningful pending state.
 */
enum CustomerSegment: string
{
    case All = 'all';
    case Active = 'active';
    case Pending = 'pending';
    case WithdrawalRequests = 'withdrawal_requests';
    case WithActiveLoans = 'with_active_loans';
    case Unassigned = 'unassigned';
    case Dormant = 'dormant';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All',
            self::Active => 'Active',
            self::Pending => 'Pending (no account)',
            self::WithdrawalRequests => 'Withdrawal requests',
            self::WithActiveLoans => 'With active loans',
            self::Unassigned => 'No agent',
            self::Dormant => 'Dormant',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Pending, self::WithdrawalRequests => 'warning',
            self::WithActiveLoans => 'info',
            self::Closed => 'danger',
            default => 'gray',
        };
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function apply(Builder $query): Builder
    {
        return match ($this) {
            self::All => $query,
            self::Active => $query->where('status', AccountStatus::Active),
            self::Dormant => $query->where('status', AccountStatus::Dormant),
            self::Closed => $query->where('status', AccountStatus::Closed),
            self::Pending => $query->where('status', AccountStatus::Active)->whereDoesntHave('savingsAccounts'),
            self::WithdrawalRequests => $query->whereHas('withdrawalRequests', fn (Builder $requests) => $requests
                ->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::Approved])),
            self::WithActiveLoans => $query->where(fn (Builder $either) => $either
                ->whereHas('loans', fn (Builder $loans) => $loans->where('status', LoanStatus::Disbursed))
                ->orWhereHas('groupLoans', fn (Builder $loans) => $loans->where('status', GroupLoanStatus::Active))),
            self::Unassigned => $query->where('status', AccountStatus::Active)->whereNull('assigned_agent_id'),
        };
    }
}
