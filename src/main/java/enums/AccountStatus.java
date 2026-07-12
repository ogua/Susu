package enums;

public enum AccountStatus {
    ACTIVE("active"),
    DORMANT("dormant"),
    CLOSED("closed");

    private final String value;

    AccountStatus(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static AccountStatus fromValue(String value) {
        for (AccountStatus status : values()) {
            if (status.value.equals(value)) {
                return status;
            }
        }
        throw new IllegalArgumentException("Unknown account status: " + value);
    }
}
