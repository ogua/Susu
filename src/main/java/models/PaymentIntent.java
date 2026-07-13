package models;

/**
 * Read-only mirror of App\Http\Resources\V1\PaymentIntentResource — the
 * desktop never initiates payments itself (that's mobile/web only per
 * Phase 4's scope), it only displays what synced from the server.
 */
public class PaymentIntent {

    private String id;
    private String flow;
    private String channel;
    private String phone;
    private long amount;
    private String amountFormatted;
    private String status;
    private String journalEntryId;
    private String createdAt;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getFlow() { return flow; }
    public void setFlow(String flow) { this.flow = flow; }

    public String getChannel() { return channel; }
    public void setChannel(String channel) { this.channel = channel; }

    public String getPhone() { return phone; }
    public void setPhone(String phone) { this.phone = phone; }

    public long getAmount() { return amount; }
    public void setAmount(long amount) { this.amount = amount; }

    public String getAmountFormatted() { return amountFormatted; }
    public void setAmountFormatted(String amountFormatted) { this.amountFormatted = amountFormatted; }

    public String getStatus() { return status; }
    public void setStatus(String status) { this.status = status; }

    public String getJournalEntryId() { return journalEntryId; }
    public void setJournalEntryId(String journalEntryId) { this.journalEntryId = journalEntryId; }

    public String getCreatedAt() { return createdAt; }
    public void setCreatedAt(String createdAt) { this.createdAt = createdAt; }
}
