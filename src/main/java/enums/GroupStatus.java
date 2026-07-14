package enums;

public enum GroupStatus {
    DRAFT("draft"),
    ACTIVE("active"),
    COMPLETED("completed");

    private final String value;

    GroupStatus(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static GroupStatus fromValue(String value) {
        for (GroupStatus status : values()) {
            if (status.value.equals(value)) {
                return status;
            }
        }
        throw new IllegalArgumentException("Unknown group status: " + value);
    }
}
