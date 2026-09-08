package service;

import enums.LoanFrequency;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.List;

/**
 * Spreads a group loan's principal into installments of a fixed periodic
 * amount — the "amount to be paid for the week/date" the member enters. Every
 * installment equals the periodic amount except the last, which carries the
 * remainder, so the schedule's principal always sums to exactly the loan
 * amount. There is no interest. The first installment falls on the start date
 * itself; each subsequent one steps forward by the frequency. Mirrors the
 * backend's {@code App\Services\Loans\PeriodicScheduleGenerator}.
 */
public class PeriodicScheduleGenerator {

    /** Refuse to build a schedule longer than this — a runaway daily plan is a data-entry mistake. */
    public static final int MAX_PERIODS = 730;

    public List<ScheduledInstallment> generate(long principal, long periodicAmount, LoanFrequency frequency,
                                                LocalDate startDate) {
        int count = periodCount(principal, periodicAmount);

        List<ScheduledInstallment> schedule = new ArrayList<>();
        LocalDate dueDate = startDate;

        for (int sequence = 1; sequence <= count; sequence++) {
            boolean last = sequence == count;
            long amount = last ? principal - (periodicAmount * (count - 1)) : periodicAmount;

            schedule.add(new ScheduledInstallment(sequence, dueDate, amount, 0));

            dueDate = frequency.addPeriod(dueDate, 1);
        }

        return schedule;
    }

    /** How many installments the principal spreads into at this periodic amount. */
    public int periodCount(long principal, long periodicAmount) {
        if (principal <= 0) {
            throw new IllegalArgumentException("The loan amount must be greater than zero.");
        }
        if (periodicAmount <= 0) {
            throw new IllegalArgumentException("The periodic amount must be greater than zero.");
        }
        if (periodicAmount >= principal) {
            return 1;
        }

        long count = principal / periodicAmount + (principal % periodicAmount > 0 ? 1 : 0);
        if (count > MAX_PERIODS) {
            throw new IllegalArgumentException(
                    "This schedule would run for over " + MAX_PERIODS
                    + " payments — increase the periodic amount or use a longer frequency.");
        }
        return (int) count;
    }
}
