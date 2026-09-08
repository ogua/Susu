package models;

import java.time.Instant;

/** One event in a group loan's security-deposit lifecycle: held | applied | refunded | seized. */
public class GroupLoanDeposit {

    private String id;
    private String groupLoanId;
    private String journalEntryId;
    private String recordedBy;
    private long amount;
    private String type;
    private Instant recordedAt;
    private String clientReference;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getGroupLoanId() { return groupLoanId; }
    public void setGroupLoanId(String groupLoanId) { this.groupLoanId = groupLoanId; }

    public String getJournalEntryId() { return journalEntryId; }
    public void setJournalEntryId(String journalEntryId) { this.journalEntryId = journalEntryId; }

    public String getRecordedBy() { return recordedBy; }
    public void setRecordedBy(String recordedBy) { this.recordedBy = recordedBy; }

    public long getAmount() { return amount; }
    public void setAmount(long amount) { this.amount = amount; }

    public String getType() { return type; }
    public void setType(String type) { this.type = type; }

    public Instant getRecordedAt() { return recordedAt; }
    public void setRecordedAt(Instant recordedAt) { this.recordedAt = recordedAt; }

    public String getClientReference() { return clientReference; }
    public void setClientReference(String clientReference) { this.clientReference = clientReference; }
}
