package models;

public class JournalLine {

    private String id;
    private String journalEntryId;
    private String ledgerAccountId;
    private long debit;
    private long credit;
    private String memo;

    public JournalLine() {}

    public JournalLine(String ledgerAccountId, long debit, long credit, String memo) {
        this.ledgerAccountId = ledgerAccountId;
        this.debit = debit;
        this.credit = credit;
        this.memo = memo;
    }

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getJournalEntryId() { return journalEntryId; }
    public void setJournalEntryId(String journalEntryId) { this.journalEntryId = journalEntryId; }

    public String getLedgerAccountId() { return ledgerAccountId; }
    public void setLedgerAccountId(String ledgerAccountId) { this.ledgerAccountId = ledgerAccountId; }

    public long getDebit() { return debit; }
    public void setDebit(long debit) { this.debit = debit; }

    public long getCredit() { return credit; }
    public void setCredit(long credit) { this.credit = credit; }

    public String getMemo() { return memo; }
    public void setMemo(String memo) { this.memo = memo; }
}
