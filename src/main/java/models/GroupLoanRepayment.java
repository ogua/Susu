package models;

import java.time.Instant;

public class GroupLoanRepayment {

    private String id;
    private String groupLoanId;
    private String groupLoanBorrowerId;
    private String journalEntryId;
    private String recordedBy;
    private long amount;
    private Instant recordedAt;
    private String clientReference;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getGroupLoanId() { return groupLoanId; }
    public void setGroupLoanId(String groupLoanId) { this.groupLoanId = groupLoanId; }

    public String getGroupLoanBorrowerId() { return groupLoanBorrowerId; }
    public void setGroupLoanBorrowerId(String groupLoanBorrowerId) { this.groupLoanBorrowerId = groupLoanBorrowerId; }

    public String getJournalEntryId() { return journalEntryId; }
    public void setJournalEntryId(String journalEntryId) { this.journalEntryId = journalEntryId; }

    public String getRecordedBy() { return recordedBy; }
    public void setRecordedBy(String recordedBy) { this.recordedBy = recordedBy; }

    public long getAmount() { return amount; }
    public void setAmount(long amount) { this.amount = amount; }

    public Instant getRecordedAt() { return recordedAt; }
    public void setRecordedAt(Instant recordedAt) { this.recordedAt = recordedAt; }

    public String getClientReference() { return clientReference; }
    public void setClientReference(String clientReference) { this.clientReference = clientReference; }
}
