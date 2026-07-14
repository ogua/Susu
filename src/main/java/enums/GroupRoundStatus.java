package enums;

public enum GroupRoundStatus {
    PENDING("pending"),
    COLLECTING("collecting"),
    COMPLETED("completed");

    private final String value;

    GroupRoundStatus(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static GroupRoundStatus fromValue(String value) {
        for (GroupRoundStatus status : values()) {
            if (status.value.equals(value)) {
                return status;
            }
        }
        throw new IllegalArgumentException("Unknown group round status: " + value);
    }
}
