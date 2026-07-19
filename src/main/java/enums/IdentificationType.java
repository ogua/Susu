package enums;

public enum IdentificationType {
    GHANA_CARD("ghana_card"),
    VOTERS_ID("voters_id"),
    PASSPORT("passport"),
    DRIVERS_LICENSE("drivers_license"),
    OTHER("other");

    private final String value;

    IdentificationType(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static IdentificationType fromValue(String value) {
        for (IdentificationType type : values()) {
            if (type.value.equals(value)) {
                return type;
            }
        }
        throw new IllegalArgumentException("Unknown identification type: " + value);
    }
}
