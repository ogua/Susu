package service;

import enums.InterestMethod;

/**
 * Pure per-period interest math — no persistence, no schedule/date logic
 * (that's ScheduleGenerator, which calls this once per period). Mirrors the
 * backend's {@code App\Services\Loans\InterestCalculator}.
 */
public class InterestCalculator {

    /**
     * Interest owed for a single period.
     *
     * <p>Flat: the rate applies to the original principal every period, so
     * this ignores the outstanding balance entirely — every installment's
     * interest is identical.
     *
     * <p>Reducing balance: the rate applies to whatever principal is still
     * outstanding *before* this period's principal portion is deducted, so
     * interest shrinks period over period as the loan is paid down.
     */
    public long periodInterest(long outstandingBalance, long originalPrincipal, int rateBasisPoints, InterestMethod method) {
        return switch (method) {
            case FLAT -> (originalPrincipal * rateBasisPoints) / 10_000;
            case REDUCING_BALANCE -> (outstandingBalance * rateBasisPoints) / 10_000;
        };
    }
}
