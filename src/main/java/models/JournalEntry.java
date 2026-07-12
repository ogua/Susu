package models;

import enums.EntryStatus;
import enums.PaymentMethod;
import enums.TransactionType;
import java.time.Instant;
import java.util.List;

public class JournalEntry {

    private String id;
    private String reference;
    private String clientReference;
    private String origin;
    private TransactionType type;
    private EntryStatus status;
    private PaymentMethod paymentMethod;
    private String description;
    private String recordedBy;
    private Instant recordedAt;
    private Instant postedAt;
    private String reversedEntryId;
    private String meta;
    private List<JournalLine> lines;

    public JournalEntry() {}

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getReference() { return reference; }
    public void setReference(String reference) { this.reference = reference; }

    public String getClientReference() { return clientReference; }
    public void setClientReference(String clientReference) { this.clientReference = clientReference; }

    public String getOrigin() { return origin; }
    public void setOrigin(String origin) { this.origin = origin; }

    public TransactionType getType() { return type; }
    public void setType(TransactionType type) { this.type = type; }

    public EntryStatus getStatus() { return status; }
    public void setStatus(EntryStatus status) { this.status = status; }

    public PaymentMethod getPaymentMethod() { return paymentMethod; }
    public void setPaymentMethod(PaymentMethod paymentMethod) { this.paymentMethod = paymentMethod; }

    public String getDescription() { return description; }
    public void setDescription(String description) { this.description = description; }

    public String getRecordedBy() { return recordedBy; }
    public void setRecordedBy(String recordedBy) { this.recordedBy = recordedBy; }

    public Instant getRecordedAt() { return recordedAt; }
    public void setRecordedAt(Instant recordedAt) { this.recordedAt = recordedAt; }

    public Instant getPostedAt() { return postedAt; }
    public void setPostedAt(Instant postedAt) { this.postedAt = postedAt; }

    public String getReversedEntryId() { return reversedEntryId; }
    public void setReversedEntryId(String reversedEntryId) { this.reversedEntryId = reversedEntryId; }

    public String getMeta() { return meta; }
    public void setMeta(String meta) { this.meta = meta; }

    public List<JournalLine> getLines() { return lines; }
    public void setLines(List<JournalLine> lines) { this.lines = lines; }

    /** Total moved by this entry (sum of one side; both sides are equal). */
    public long amount() {
        if (lines == null) {
            return 0;
        }
        return lines.stream().mapToLong(JournalLine::getDebit).sum();
    }
}
