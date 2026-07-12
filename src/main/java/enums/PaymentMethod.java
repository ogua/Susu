package enums;

public enum PaymentMethod {
    CASH("cash"),
    MOBILE_MONEY("mobile_money"),
    INTERNAL("internal");

    private final String value;

    PaymentMethod(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static PaymentMethod fromValue(String value) {
        for (PaymentMethod method : values()) {
            if (method.value.equals(value)) {
                return method;
            }
        }
        throw new IllegalArgumentException("Unknown payment method: " + value);
    }
}
