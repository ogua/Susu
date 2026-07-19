package enums;

public enum MaritalStatus {
    SINGLE("single"),
    MARRIED("married"),
    DIVORCED("divorced"),
    WIDOWED("widowed"),
    SEPARATED("separated");

    private final String value;

    MaritalStatus(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static MaritalStatus fromValue(String value) {
        for (MaritalStatus status : values()) {
            if (status.value.equals(value)) {
                return status;
            }
        }
        throw new IllegalArgumentException("Unknown marital status: " + value);
    }
}
