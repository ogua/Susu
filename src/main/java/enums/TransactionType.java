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
    GROUP_LOAN_DEPOSIT_HELD("group_loan_deposit_held"),
    // Legacy — produced by the pre-savings-account deposit-escrow model. No
    // longer written; kept so historical journal entries still parse.
    GROUP_LOAN_DEPOSIT_REFUNDED("group_loan_deposit_refunded"),
    GROUP_LOAN_DEPOSIT_APPLIED("group_loan_deposit_applied"),
    LOAN_RESTRUCTURE("loan_restructure"),
    LOAN_TOP_UP("loan_top_up"),
    GROUP_LOAN_RESTRUCTURE("group_loan_restructure"),
    GROUP_LOAN_TOP_UP("group_loan_top_up"),
    LOAN_WRITE_OFF("loan_write_off"),
    GROUP_LOAN_WRITE_OFF("group_loan_write_off"),
    SAVINGS_APPLIED_TO_LOAN_WRITE_OFF("savings_applied_to_loan_write_off"),
    SAVINGS_APPLIED_TO_GROUP_LOAN_WRITE_OFF("savings_applied_to_group_loan_write_off"),
    FIXED_DEPOSIT_MATURITY("fixed_deposit_maturity"),
    SHARE_PURCHASE("share_purchase");

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
