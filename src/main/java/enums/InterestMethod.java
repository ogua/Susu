package enums;

public enum InterestMethod {
    /** Rate applies per period to the original principal for the whole term — every installment's interest is identical. */
    FLAT("flat"),
    /** Equal principal each period; interest computed on the declining outstanding balance, so it shrinks over time. */
    REDUCING_BALANCE("reducing_balance");

    private final String value;

    InterestMethod(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static InterestMethod fromValue(String value) {
        for (InterestMethod method : values()) {
            if (method.value.equals(value)) {
                return method;
            }
        }
        throw new IllegalArgumentException("Unknown interest method: " + value);
    }
}
