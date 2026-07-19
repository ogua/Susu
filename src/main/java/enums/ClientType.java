package enums;

public enum ClientType {
    INDIVIDUAL("individual"),
    BUSINESS("business");

    private final String value;

    ClientType(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static ClientType fromValue(String value) {
        for (ClientType type : values()) {
            if (type.value.equals(value)) {
                return type;
            }
        }
        throw new IllegalArgumentException("Unknown client type: " + value);
    }
}
