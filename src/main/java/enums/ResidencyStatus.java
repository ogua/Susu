package enums;

public enum ResidencyStatus {
    TENANT("tenant"),
    PROPERTY_OWNER("property_owner");

    private final String value;

    ResidencyStatus(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static ResidencyStatus fromValue(String value) {
        for (ResidencyStatus status : values()) {
            if (status.value.equals(value)) {
                return status;
            }
        }
        throw new IllegalArgumentException("Unknown residency status: " + value);
    }
}
