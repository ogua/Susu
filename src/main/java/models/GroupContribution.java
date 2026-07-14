package models;

import java.time.Instant;

public class GroupContribution {

    private String id;
    private String groupRoundId;
    private String groupMemberId;
    private String journalEntryId;
    private String recordedBy;
    private long amount;
    private Instant recordedAt;
    private String clientReference;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getGroupRoundId() { return groupRoundId; }
    public void setGroupRoundId(String groupRoundId) { this.groupRoundId = groupRoundId; }

    public String getGroupMemberId() { return groupMemberId; }
    public void setGroupMemberId(String groupMemberId) { this.groupMemberId = groupMemberId; }

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
