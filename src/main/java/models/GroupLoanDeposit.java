package models;

import java.time.Instant;

/**
 * A member's security deposit being paid into one of their savings accounts.
 * {@code type} is "held" going forward; "applied"/"refunded"/"seized" are
 * legacy values from the pre-savings-account escrow model.
 */
public class GroupLoanDeposit {

    private String id;
    private String groupLoanId;
    private String savingsAccountId;
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

    public String getSavingsAccountId() { return savingsAccountId; }
    public void setSavingsAccountId(String savingsAccountId) { this.savingsAccountId = savingsAccountId; }

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
