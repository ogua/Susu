package enums;

import java.time.LocalDate;

public enum LoanFrequency {
    WEEKLY("weekly"),
    MONTHLY("monthly");

    private final String value;

    LoanFrequency(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public LocalDate addPeriod(LocalDate date, int periods) {
        return switch (this) {
            case WEEKLY -> date.plusWeeks(periods);
            case MONTHLY -> date.plusMonths(periods);
        };
    }

    public static LoanFrequency fromValue(String value) {
        for (LoanFrequency frequency : values()) {
            if (frequency.value.equals(value)) {
                return frequency;
            }
        }
        throw new IllegalArgumentException("Unknown loan frequency: " + value);
    }
}
