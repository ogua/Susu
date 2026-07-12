package enums;

public enum TransactionType {
    COLLECTION("collection"),
    COMMISSION("commission"),
    WITHDRAWAL("withdrawal"),
    REMITTANCE("remittance"),
    REVERSAL("reversal"),
    ADJUSTMENT("adjustment");

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
