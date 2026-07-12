package models;

import enums.AgentSummaryStatus;

public class AgentDailySummary {

    private String id;
    private String agentId;
    private String summaryDate;
    private long collectionsTotal;
    private int collectionsCount;
    private long expectedCash;
    private Long declaredCash;
    private Long variance;
    private AgentSummaryStatus status;
    private String reconciledBy;
    private String notes;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getAgentId() { return agentId; }
    public void setAgentId(String agentId) { this.agentId = agentId; }

    public String getSummaryDate() { return summaryDate; }
    public void setSummaryDate(String summaryDate) { this.summaryDate = summaryDate; }

    public long getCollectionsTotal() { return collectionsTotal; }
    public void setCollectionsTotal(long collectionsTotal) { this.collectionsTotal = collectionsTotal; }

    public int getCollectionsCount() { return collectionsCount; }
    public void setCollectionsCount(int collectionsCount) { this.collectionsCount = collectionsCount; }

    public long getExpectedCash() { return expectedCash; }
    public void setExpectedCash(long expectedCash) { this.expectedCash = expectedCash; }

    public Long getDeclaredCash() { return declaredCash; }
    public void setDeclaredCash(Long declaredCash) { this.declaredCash = declaredCash; }

    public Long getVariance() { return variance; }
    public void setVariance(Long variance) { this.variance = variance; }

    public AgentSummaryStatus getStatus() { return status; }
    public void setStatus(AgentSummaryStatus status) { this.status = status; }

    public String getReconciledBy() { return reconciledBy; }
    public void setReconciledBy(String reconciledBy) { this.reconciledBy = reconciledBy; }

    public String getNotes() { return notes; }
    public void setNotes(String notes) { this.notes = notes; }
}
