package enums;

/** Mirrors the backend's App\Enums\CommissionType (all five cases, same string values). */
public enum CommissionType {
    NONE("none"),
    FIRST_CONTRIBUTION_PER_CYCLE("first_contribution_per_cycle"),
    PERCENTAGE("percentage"),
    PERCENTAGE_OF_BALANCE_PER_CYCLE("percentage_of_balance_per_cycle"),
    FLAT_PER_CYCLE("flat_per_cycle");

    private final String value;

    CommissionType(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static CommissionType fromValue(String value) {
        for (CommissionType type : values()) {
            if (type.value.equals(value)) {
                return type;
            }
        }
        throw new IllegalArgumentException("Unknown commission type: " + value);
    }
}
