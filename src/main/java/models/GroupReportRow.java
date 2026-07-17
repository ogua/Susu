package models;

/** One susu group's health snapshot — Groups report read model. */
public class GroupReportRow {

    private final String name;
    private final String code;
    private final String status;
    private final int membersCount;
    private final String currentRound;
    private final long roundExpected;
    private final long roundCollected;
    private final int roundsPaidOut;
    private final long lifetimeCollected;

    public GroupReportRow(String name, String code, String status, int membersCount,
                          String currentRound, long roundExpected, long roundCollected,
                          int roundsPaidOut, long lifetimeCollected) {
        this.name = name;
        this.code = code;
        this.status = status;
        this.membersCount = membersCount;
        this.currentRound = currentRound;
        this.roundExpected = roundExpected;
        this.roundCollected = roundCollected;
        this.roundsPaidOut = roundsPaidOut;
        this.lifetimeCollected = lifetimeCollected;
    }

    public String getName() { return name; }
    public String getCode() { return code; }
    public String getStatus() { return status; }
    public int getMembersCount() { return membersCount; }
    public String getCurrentRound() { return currentRound; }
    public long getRoundExpected() { return roundExpected; }
    public long getRoundCollected() { return roundCollected; }
    public int getRoundsPaidOut() { return roundsPaidOut; }
    public long getLifetimeCollected() { return lifetimeCollected; }
}
