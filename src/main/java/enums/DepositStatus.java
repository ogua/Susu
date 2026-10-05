package enums;

/**
 * The state of a group loan member's security deposit.
 *
 * <ul>
 *   <li>PENDING — agreed at issue time, not yet paid in.</li>
 *   <li>HELD — paid in, credited straight into the member's chosen savings
 *       account. There is no further transition; it stays HELD for the life of
 *       the loan and only gates whether "record deposit" is still offered.</li>
 * </ul>
 */
public enum DepositStatus {
    PENDING("pending"),
    HELD("held");

    private final String value;

    DepositStatus(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static DepositStatus fromValue(String value) {
        for (DepositStatus status : values()) {
            if (status.value.equals(value)) {
                return status;
            }
        }
        throw new IllegalArgumentException("Unknown deposit status: " + value);
    }
}
