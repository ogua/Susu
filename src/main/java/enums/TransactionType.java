package enums;

public enum TransactionType {
    COLLECTION("collection"),
    COMMISSION("commission"),
    WITHDRAWAL("withdrawal"),
    REMITTANCE("remittance"),
    REVERSAL("reversal"),
    ADJUSTMENT("adjustment"),
    DISBURSEMENT("disbursement"),
    REPAYMENT("repayment"),
    PENALTY("penalty"),
    GROUP_CONTRIBUTION("group_contribution"),
    GROUP_PAYOUT("group_payout"),
    GROUP_LOAN_DISBURSEMENT("group_loan_disbursement"),
    GROUP_LOAN_REPAYMENT("group_loan_repayment"),
    LOAN_RESTRUCTURE("loan_restructure"),
    LOAN_TOP_UP("loan_top_up"),
    GROUP_LOAN_RESTRUCTURE("group_loan_restructure"),
    GROUP_LOAN_TOP_UP("group_loan_top_up"),
    LOAN_WRITE_OFF("loan_write_off"),
    GROUP_LOAN_WRITE_OFF("group_loan_write_off");

    private final String value;

    TransactionType(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static TransactionType fromValue(String value) {
        for (TransactionType type : values()) {
            if (type.value.equals(value)) {
                return type;
            }
        }
        throw new IllegalArgumentException("Unknown transaction type: " + value);
    }
}
