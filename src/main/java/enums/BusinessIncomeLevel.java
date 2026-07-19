package enums;

public enum BusinessIncomeLevel {
    LOW("low"),
    MEDIUM("medium"),
    HIGH("high");

    private final String value;

    BusinessIncomeLevel(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static BusinessIncomeLevel fromValue(String value) {
        for (BusinessIncomeLevel level : values()) {
            if (level.value.equals(value)) {
                return level;
            }
        }
        throw new IllegalArgumentException("Unknown business income level: " + value);
    }
}
