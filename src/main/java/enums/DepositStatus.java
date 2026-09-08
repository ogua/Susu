package enums;

/**
 * The state of a group loan member's security deposit.
 *
 * <ul>
 *   <li>PENDING — agreed at issue time, not yet paid in.</li>
 *   <li>HELD — paid in and sitting on the books as a per-loan liability.</li>
 *   <li>SETTLED — no longer held (refunded, applied to the balance, or seized
 *       on write-off); the group_loan_deposits rows carry which.</li>
 * </ul>
 */
public enum DepositStatus {
    PENDING("pending"),
    HELD("held"),
    SETTLED("settled");

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
