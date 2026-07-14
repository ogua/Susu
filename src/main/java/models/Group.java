package models;

import enums.GroupStatus;
import java.time.Instant;
import java.util.List;

public class Group {

    private String id;
    private String liabilityAccountId;
    private String createdBy;
    private String name;
    private String code;
    private long contributionAmount;
    private String frequency;
    private GroupStatus status;
    private Instant activatedAt;
    private Instant completedAt;

    /** Populated by detail queries; not always present. */
    private List<GroupMember> members;
    private List<GroupRound> rounds;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getLiabilityAccountId() { return liabilityAccountId; }
    public void setLiabilityAccountId(String liabilityAccountId) { this.liabilityAccountId = liabilityAccountId; }

    public String getCreatedBy() { return createdBy; }
    public void setCreatedBy(String createdBy) { this.createdBy = createdBy; }

    public String getName() { return name; }
    public void setName(String name) { this.name = name; }

    public String getCode() { return code; }
    public void setCode(String code) { this.code = code; }

    public long getContributionAmount() { return contributionAmount; }
    public void setContributionAmount(long contributionAmount) { this.contributionAmount = contributionAmount; }

    public String getFrequency() { return frequency; }
    public void setFrequency(String frequency) { this.frequency = frequency; }

    public GroupStatus getStatus() { return status; }
    public void setStatus(GroupStatus status) { this.status = status; }

    public Instant getActivatedAt() { return activatedAt; }
    public void setActivatedAt(Instant activatedAt) { this.activatedAt = activatedAt; }

    public Instant getCompletedAt() { return completedAt; }
    public void setCompletedAt(Instant completedAt) { this.completedAt = completedAt; }

    public List<GroupMember> getMembers() { return members; }
    public void setMembers(List<GroupMember> members) { this.members = members; }

    public List<GroupRound> getRounds() { return rounds; }
    public void setRounds(List<GroupRound> rounds) { this.rounds = rounds; }
}
