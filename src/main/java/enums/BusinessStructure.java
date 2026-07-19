package enums;

public enum BusinessStructure {
    SOLE_PROPRIETORSHIP("sole_proprietorship"),
    PARTNERSHIP("partnership"),
    LIMITED_LIABILITY_COMPANY("limited_liability_company"),
    NGO_CBO("ngo_cbo"),
    COOPERATIVE("cooperative"),
    OTHER("other");

    private final String value;

    BusinessStructure(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static BusinessStructure fromValue(String value) {
        for (BusinessStructure structure : values()) {
            if (structure.value.equals(value)) {
                return structure;
            }
        }
        throw new IllegalArgumentException("Unknown business structure: " + value);
    }
}
