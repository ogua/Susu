<?php

namespace App\Actions\Customers;

use App\Enums\AccountStatus;
use App\Enums\CustomerSegment;
use App\Models\Customer;
use App\Models\SavingsAccount;
use Illuminate\Database\Eloquent\Builder;

/**
 * Headline numbers for the customer list: a count per segment plus total
 * savings held and new registrations this month. Scoped by the caller (one
 * branch, or a whole company for company admins).
 */
class BuildCustomerOverviewAction
{
    /**
     * @param  Builder<Customer>  $scope
     * @return array{segments: array<string, int>, total: int, new_this_month: int, savings_balance: int}
     */
    public function execute(Builder $scope): array
    {
        $segments = [];
        foreach (CustomerSegment::cases() as $segment) {
            $segments[$segment->value] = $segment->apply(clone $scope)->count();
        }

        return [
            'segments' => $segments,
            'total' => $segments[CustomerSegment::All->value],
            'new_this_month' => (clone $scope)->where('created_at', '>=', now()->startOfMonth())->count(),
            'savings_balance' => (int) SavingsAccount::whereIn('customer_id', (clone $scope)->select('id'))
                ->where('status', AccountStatus::Active)
                ->sum('balance'),
        ];
    }
}
