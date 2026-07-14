<?php

namespace App\Actions\Reports;

use App\Models\Branch;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @phpstan-type CashPositionResult array{accounts: Collection<int, LedgerAccount>, total: int}
 */
class BuildCashPositionAction
{
    /**
     * The branch cash account (money already remitted to the office) plus
     * every field agent's cash-in-hand account for the branch — each
     * backed by its real ledger balance, not a computed estimate.
     *
     * @return CashPositionResult
     */
    public function execute(Branch $branch): array
    {
        $agentIds = User::query()
            ->where('branch_id', $branch->id)
            ->role('field_agent')
            ->pluck('id');

        $accounts = LedgerAccount::query()
            ->where('branch_id', $branch->id)
            ->where(function (Builder $query) use ($agentIds): void {
                $query->whereNull('accountable_type')
                    ->orWhere(function (Builder $query) use ($agentIds): void {
                        $query->where('accountable_type', User::class)
                            ->whereIn('accountable_id', $agentIds);
                    });
            })
            ->orderBy('accountable_type')
            ->get();

        return [
            'accounts' => $accounts,
            'total' => $accounts->sum('balance'),
        ];
    }
}
