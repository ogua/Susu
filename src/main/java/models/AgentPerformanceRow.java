package models;

/** One agent's aggregated day-sheet + commission figures — Agent Performance report read model. */
public class AgentPerformanceRow {

    private final String agentName;
    private final int daysWorked;
    private final long collectionsCount;
    private final long collectionsTotal;
    private final long varianceTotal;
    private final int unreconciledDays;
    private final long commissionTotal;

    public AgentPerformanceRow(String agentName, int daysWorked, long collectionsCount,
                               long collectionsTotal, long varianceTotal, int unreconciledDays,
                               long commissionTotal) {
        this.agentName = agentName;
        this.daysWorked = daysWorked;
        this.collectionsCount = collectionsCount;
        this.collectionsTotal = collectionsTotal;
        this.varianceTotal = varianceTotal;
        this.unreconciledDays = unreconciledDays;
        this.commissionTotal = commissionTotal;
    }

    public String getAgentName() { return agentName; }
    public int getDaysWorked() { return daysWorked; }
    public long getCollectionsCount() { return collectionsCount; }
    public long getCollectionsTotal() { return collectionsTotal; }
    public long getVarianceTotal() { return varianceTotal; }
    public int getUnreconciledDays() { return unreconciledDays; }
    public long getCommissionTotal() { return commissionTotal; }
}
