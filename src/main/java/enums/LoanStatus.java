package enums;

public enum LoanStatus {
    APPLIED("applied"),
    APPROVED("approved"),
    REJECTED("rejected"),
    DISBURSED("disbursed"),
    CLOSED("closed"),
    WRITTEN_OFF("written_off"),
    REFINANCED("refinanced");

    private final String value;

    LoanStatus(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static LoanStatus fromValue(String value) {
        for (LoanStatus status : values()) {
            if (status.value.equals(value)) {
                return status;
            }
        }
        throw new IllegalArgumentException("Unknown loan status: " + value);
    }
}
