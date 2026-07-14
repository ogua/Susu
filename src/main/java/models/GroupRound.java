package models;

import enums.GroupRoundStatus;
import java.time.Instant;
import java.time.LocalDate;

public class GroupRound {

    private String id;
    private String groupId;
    private String payoutMemberId;
    private String payoutEntryId;
    private int roundNumber;
    private LocalDate dueDate;
    private long totalExpected;
    private long totalCollected;
    private GroupRoundStatus status;
    private Instant paidOutAt;

    /** Populated by detail queries; not always present. */
    private GroupMember payoutMember;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getGroupId() { return groupId; }
    public void setGroupId(String groupId) { this.groupId = groupId; }

    public String getPayoutMemberId() { return payoutMemberId; }
    public void setPayoutMemberId(String payoutMemberId) { this.payoutMemberId = payoutMemberId; }

    public String getPayoutEntryId() { return payoutEntryId; }
    public void setPayoutEntryId(String payoutEntryId) { this.payoutEntryId = payoutEntryId; }

    public int getRoundNumber() { return roundNumber; }
    public void setRoundNumber(int roundNumber) { this.roundNumber = roundNumber; }

    public LocalDate getDueDate() { return dueDate; }
    public void setDueDate(LocalDate dueDate) { this.dueDate = dueDate; }

    public long getTotalExpected() { return totalExpected; }
    public void setTotalExpected(long totalExpected) { this.totalExpected = totalExpected; }

    public long getTotalCollected() { return totalCollected; }
    public void setTotalCollected(long totalCollected) { this.totalCollected = totalCollected; }

    public GroupRoundStatus getStatus() { return status; }
    public void setStatus(GroupRoundStatus status) { this.status = status; }

    public Instant getPaidOutAt() { return paidOutAt; }
    public void setPaidOutAt(Instant paidOutAt) { this.paidOutAt = paidOutAt; }

    public GroupMember getPayoutMember() { return payoutMember; }
    public void setPayoutMember(GroupMember payoutMember) { this.payoutMember = payoutMember; }

    public long remaining() {
        return Math.max(0, totalExpected - totalCollected);
    }
}
