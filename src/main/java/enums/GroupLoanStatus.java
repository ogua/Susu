package enums;

public enum GroupLoanStatus {
    APPLIED("applied"),
    APPROVED("approved"),
    REJECTED("rejected"),
    DISBURSED("disbursed"),
    CLOSED("closed"),
    WRITTEN_OFF("written_off"),
    REFINANCED("refinanced");

    private final String value;

    GroupLoanStatus(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static GroupLoanStatus fromValue(String value) {
        for (GroupLoanStatus status : values()) {
            if (status.value.equals(value)) {
                return status;
            }
        }
        throw new IllegalArgumentException("Unknown group loan status: " + value);
    }
}
