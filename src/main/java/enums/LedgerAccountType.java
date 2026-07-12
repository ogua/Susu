package enums;

public enum LedgerAccountType {
    ASSET("asset"),
    LIABILITY("liability"),
    INCOME("income"),
    EXPENSE("expense"),
    EQUITY("equity");

    private final String value;

    LedgerAccountType(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    /** Which side increases this account (mirrors the backend's LedgerAccountType). */
    public boolean isNormalBalanceDebit() {
        return this == ASSET || this == EXPENSE;
    }

    public static LedgerAccountType fromValue(String value) {
        for (LedgerAccountType type : values()) {
            if (type.value.equals(value)) {
                return type;
            }
        }
        throw new IllegalArgumentException("Unknown ledger account type: " + value);
    }
}
