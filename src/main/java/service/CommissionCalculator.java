package service;

import enums.CommissionType;
import models.SavingsAccount;
import models.SavingsProduct;

/**
 * Walks a deposit's contribution units through the account's susu cycle,
 * computing the commission and the counters after posting. Byte-for-byte
 * mirror of the backend's {@code App\Services\Savings\CommissionCalculator}
 * — see that class for the cycle semantics this implements.
 */
public class CommissionCalculator {

    public CycleResult simulate(SavingsAccount account, SavingsProduct product, int units, long amount) {
        int cycleLength = Math.max(1, product.getCycleLengthDays());

        int position = account.getContributionsThisCycle();
        int cycleNumber = account.getCycleNumber();
        int cyclesStarted = 0;

        for (int unit = 0; unit < units; unit++) {
            position++;
            if (position == 1) {
                cyclesStarted++;
            }
            if (position >= cycleLength && unit < units - 1) {
                position = 0;
                cycleNumber++;
            }
        }

        // A payment landing exactly on the cycle's last unit also rolls over,
        // so the next deposit starts a fresh cycle.
        if (position >= cycleLength) {
            position = 0;
            cycleNumber++;
        }

        // Fixed deposits and shares are not susu cycles: whatever an old
        // product row still says, they never carry commission.
        long commission = !product.isCycleBased() ? 0 : switch (product.getCommissionType()) {
            case NONE -> 0;
            case FIRST_CONTRIBUTION_PER_CYCLE -> (long) cyclesStarted * account.getContributionAmount();
            case PERCENTAGE -> (amount * product.getCommissionValue()) / 10_000;
            case PERCENTAGE_OF_BALANCE_PER_CYCLE -> (long) cyclesStarted * ((account.getBalance() * product.getCommissionValue()) / 10_000);
            case FLAT_PER_CYCLE -> (long) cyclesStarted * product.getCommissionValue();
        };

        return new CycleResult(Math.min(commission, amount), cyclesStarted, position, cycleNumber);
    }
}
