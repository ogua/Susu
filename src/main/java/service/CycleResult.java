package service;

/**
 * Result of simulating a deposit's contribution units against an account's
 * susu cycle: the commission owed and where the counters land. Mirrors the
 * backend's {@code App\Services\Savings\CycleResult}.
 */
public record CycleResult(long commissionAmount, int cyclesStarted, int newContributionsThisCycle, int newCycleNumber) {
}
