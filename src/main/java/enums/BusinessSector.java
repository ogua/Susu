package enums;

public enum BusinessSector {
    AGRICULTURE("agriculture"),
    TRADING_RETAIL("trading_retail"),
    MANUFACTURING("manufacturing"),
    SERVICES("services"),
    CONSTRUCTION("construction"),
    TRANSPORTATION("transportation"),
    HOSPITALITY("hospitality"),
    TECHNOLOGY("technology"),
    OTHER("other");

    private final String value;

    BusinessSector(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static BusinessSector fromValue(String value) {
        for (BusinessSector sector : values()) {
            if (sector.value.equals(value)) {
                return sector;
            }
        }
        throw new IllegalArgumentException("Unknown business sector: " + value);
    }
}
