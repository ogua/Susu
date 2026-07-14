package enums;

public enum InstallmentStatus {
    PENDING("pending"),
    PARTIALLY_PAID("partially_paid"),
    PAID("paid"),
    OVERDUE("overdue");

    private final String value;

    InstallmentStatus(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static InstallmentStatus fromValue(String value) {
        for (InstallmentStatus status : values()) {
            if (status.value.equals(value)) {
                return status;
            }
        }
        throw new IllegalArgumentException("Unknown installment status: " + value);
    }
}
