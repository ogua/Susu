package enums;

public enum EntryStatus {
    PENDING("pending"),
    COMPLETED("completed"),
    FAILED("failed"),
    REVERSED("reversed");

    private final String value;

    EntryStatus(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static EntryStatus fromValue(String value) {
        for (EntryStatus status : values()) {
            if (status.value.equals(value)) {
                return status;
            }
        }
        throw new IllegalArgumentException("Unknown entry status: " + value);
    }
}
