package enums;

public enum WithdrawalStatus {
    PENDING("pending"),
    APPROVED("approved"),
    REJECTED("rejected"),
    PAID("paid");

    private final String value;

    WithdrawalStatus(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static WithdrawalStatus fromValue(String value) {
        for (WithdrawalStatus status : values()) {
            if (status.value.equals(value)) {
                return status;
            }
        }
        throw new IllegalArgumentException("Unknown withdrawal status: " + value);
    }
}
