package service;

import enums.InterestMethod;
import enums.LoanFrequency;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.List;

/**
 * Builds the full repayment schedule for a loan. Principal is split as
 * equally as possible across periods, with any rounding remainder absorbed
 * into the *last* installment — so the schedule's principal always sums to
 * exactly the loan amount, never a pesewa more or less. Mirrors the
 * backend's {@code App\Services\Loans\ScheduleGenerator}.
 */
public class ScheduleGenerator {

    private final InterestCalculator interest = new InterestCalculator();

    public List<ScheduledInstallment> generate(long principal, int rateBasisPoints, int termPeriods,
                                                InterestMethod method, LoanFrequency frequency, LocalDate disbursedAt) {
        long principalPerPeriod = principal / termPeriods;
        long lastPeriodRemainder = principal - (principalPerPeriod * termPeriods);

        List<ScheduledInstallment> schedule = new ArrayList<>();
        long balance = principal;
        LocalDate dueDate = disbursedAt;

        for (int period = 1; period <= termPeriods; period++) {
            dueDate = frequency.addPeriod(dueDate, 1);
            long periodPrincipal = principalPerPeriod + (period == termPeriods ? lastPeriodRemainder : 0);
            long periodInterest = interest.periodInterest(balance, principal, rateBasisPoints, method);

            schedule.add(new ScheduledInstallment(period, dueDate, periodPrincipal, periodInterest));

            balance -= periodPrincipal;
        }

        return schedule;
    }
}
