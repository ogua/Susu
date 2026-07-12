package service;

import enums.CommissionType;
import models.SavingsAccount;
import models.SavingsProduct;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;

/** Mirrors tests/Unit/CommissionCalculatorTest.php — same cycle semantics, same assertions. */
class CommissionCalculatorTest {

    private static SavingsAccount account(int position, int cycleNumber) {
        SavingsAccount account = new SavingsAccount();
        account.setContributionAmount(500);
        account.setContributionsThisCycle(position);
        account.setCycleNumber(cycleNumber);
        return account;
    }

    private static SavingsProduct product(int cycleLength, CommissionType type, long value) {
        SavingsProduct product = new SavingsProduct();
        product.setCycleLengthDays(cycleLength);
        product.setCommissionType(type);
        product.setCommissionValue(value);
        return product;
    }

    @Test
    void chargesOneContributionAsCommissionWhenACycleStarts() {
        CycleResult result = new CommissionCalculator().simulate(
                account(0, 1), product(31, CommissionType.FIRST_CONTRIBUTION_PER_CYCLE, 0), 1, 500);

        assertEquals(500, result.commissionAmount());
        assertEquals(1, result.cyclesStarted());
        assertEquals(1, result.newContributionsThisCycle());
    }

    @Test
    void chargesNothingMidCycle() {
        CycleResult result = new CommissionCalculator().simulate(
                account(5, 1), product(31, CommissionType.FIRST_CONTRIBUTION_PER_CYCLE, 0), 2, 1000);

        assertEquals(0, result.commissionAmount());
        assertEquals(7, result.newContributionsThisCycle());
    }

    @Test
    void rollsTheCycleOverWhenTheLastContributionLands() {
        CycleResult result = new CommissionCalculator().simulate(
                account(30, 1), product(31, CommissionType.FIRST_CONTRIBUTION_PER_CYCLE, 0), 1, 500);

        assertEquals(0, result.newContributionsThisCycle());
        assertEquals(2, result.newCycleNumber());
        assertEquals(0, result.commissionAmount());
    }

    @Test
    void chargesCommissionAgainWhenAPaymentCrossesIntoANewCycle() {
        // 3 units from position 30 of a 31-day cycle: 31 (completes), 1 (new cycle — fee), 2.
        CycleResult result = new CommissionCalculator().simulate(
                account(30, 1), product(31, CommissionType.FIRST_CONTRIBUTION_PER_CYCLE, 0), 3, 1500);

        assertEquals(500, result.commissionAmount());
        assertEquals(1, result.cyclesStarted());
        assertEquals(2, result.newCycleNumber());
        assertEquals(2, result.newContributionsThisCycle());
    }

    @Test
    void computesPercentageCommissionInBasisPoints() {
        CycleResult result = new CommissionCalculator().simulate(
                account(3, 1), product(31, CommissionType.PERCENTAGE, 250), 2, 1000); // 2.5%

        assertEquals(25, result.commissionAmount());
    }

    @Test
    void computesFlatCommissionPerCycleStarted() {
        CycleResult result = new CommissionCalculator().simulate(
                account(0, 1), product(31, CommissionType.FLAT_PER_CYCLE, 300), 1, 500);

        assertEquals(300, result.commissionAmount());
    }
}
