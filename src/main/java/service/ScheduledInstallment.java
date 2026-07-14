package service;

import java.time.LocalDate;

/** One not-yet-persisted row of a generated repayment schedule. */
public record ScheduledInstallment(int sequence, LocalDate dueDate, long principalDue, long interestDue) {
}
