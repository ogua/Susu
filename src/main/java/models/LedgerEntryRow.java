package models;

/** One posted journal line with a running balance — Account Ledger read model. */
public class LedgerEntryRow {

    private final String recordedAt;
    private final String reference;
    private final String type;
    private final String description;
    private final long debit;
    private final long credit;
    private final long runningBalance;

    public LedgerEntryRow(String recordedAt, String reference, String type, String description,
                          long debit, long credit, long runningBalance) {
        this.recordedAt = recordedAt;
        this.reference = reference;
        this.type = type;
        this.description = description;
        this.debit = debit;
        this.credit = credit;
        this.runningBalance = runningBalance;
    }

    public String getRecordedAt() { return recordedAt; }
    public String getReference() { return reference; }
    public String getType() { return type; }
    public String getDescription() { return description; }
    public long getDebit() { return debit; }
    public long getCredit() { return credit; }
    public long getRunningBalance() { return runningBalance; }
}
