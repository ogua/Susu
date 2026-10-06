<?php

namespace App\Services\Savings;

use App\Enums\CommissionType;
use App\Models\SavingsAccount;

/**
 * Walks a deposit's contribution units through the account's susu cycle,
 * computing the commission and the counters after posting.
 *
 * Cycle semantics: a cycle is cycle_length_days contributions; position 1 of
 * every cycle is where per-cycle commission attaches (Ghanaian susu practice:
 * "the first day's money is the collector's").
 */
class CommissionCalculator
{
    public function simulate(SavingsAccount $account, int $units, int $amount): CycleResult
    {
        $product = $account->product;
        $cycleLength = max(1, $product->cycle_length_days);

        $position = $account->contributions_this_cycle;
        $cycleNumber = $account->cycle_number;
        $cyclesStarted = 0;

        for ($unit = 0; $unit < $units; $unit++) {
            $position++;
            if ($position === 1) {
                $cyclesStarted++;
            }
            if ($position >= $cycleLength && $unit < $units - 1) {
                $position = 0;
                $cycleNumber++;
            }
        }

        // A payment landing exactly on the cycle's last unit also rolls over,
        // so the next deposit starts a fresh cycle.
        if ($position >= $cycleLength) {
            $position = 0;
            $cycleNumber++;
        }

        // Fixed deposits and shares are not susu cycles: whatever an old
        // product row still says, they never carry commission.
        $commission = ! $product->isCycleBased() ? 0 : match ($product->commission_type) {
            CommissionType::None => 0,
            CommissionType::FirstContributionPerCycle => $cyclesStarted * $account->contribution_amount,
            CommissionType::Percentage => intdiv($amount * $product->commission_value, 10_000),
            CommissionType::PercentageOfBalancePerCycle => $cyclesStarted * intdiv($account->balance * $product->commission_value, 10_000),
            CommissionType::FlatPerCycle => $cyclesStarted * $product->commission_value,
        };

        return new CycleResult(
            commissionAmount: min($commission, $amount),
            cyclesStarted: $cyclesStarted,
            newContributionsThisCycle: $position,
            newCycleNumber: $cycleNumber,
        );
    }
}
