package enums;

public enum AgentSummaryStatus {
    OPEN("open"),
    SUBMITTED("submitted"),
    RECONCILED("reconciled"),
    FLAGGED("flagged");

    private final String value;

    AgentSummaryStatus(String value) {
        this.value = value;
    }

    public String value() {
        return value;
    }

    public static AgentSummaryStatus fromValue(String value) {
        for (AgentSummaryStatus status : values()) {
            if (status.value.equals(value)) {
                return status;
            }
        }
        throw new IllegalArgumentException("Unknown agent summary status: " + value);
    }
}
