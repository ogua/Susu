package models;

/** One collection journal entry with its agent context — Collections report read model. */
public class CollectionsRow {

    private final String recordedAt;
    private final String reference;
    private final String agentName;
    private final String description;
    private final String paymentMethod;
    private final String status;
    private final long amount;

    public CollectionsRow(String recordedAt, String reference, String agentName,
                          String description, String paymentMethod, String status, long amount) {
        this.recordedAt = recordedAt;
        this.reference = reference;
        this.agentName = agentName;
        this.description = description;
        this.paymentMethod = paymentMethod;
        this.status = status;
        this.amount = amount;
    }

    public String getRecordedAt() { return recordedAt; }
    public String getReference() { return reference; }
    public String getAgentName() { return agentName; }
    public String getDescription() { return description; }
    public String getPaymentMethod() { return paymentMethod; }
    public String getStatus() { return status; }
    public long getAmount() { return amount; }
}
