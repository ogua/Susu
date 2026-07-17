package service;

import java.util.List;
import models.LedgerEntryRow;

/** Result of ReportService.accountLedger(): the period's lines plus balances. */
public class AccountLedgerResult {

    private final long openingBalance;
    private final long closingBalance;
    private final long totalDebits;
    private final long totalCredits;
    private final List<LedgerEntryRow> rows;

    public AccountLedgerResult(long openingBalance, long closingBalance,
                               long totalDebits, long totalCredits, List<LedgerEntryRow> rows) {
        this.openingBalance = openingBalance;
        this.closingBalance = closingBalance;
        this.totalDebits = totalDebits;
        this.totalCredits = totalCredits;
        this.rows = rows;
    }

    public long getOpeningBalance() { return openingBalance; }
    public long getClosingBalance() { return closingBalance; }
    public long getTotalDebits() { return totalDebits; }
    public long getTotalCredits() { return totalCredits; }
    public List<LedgerEntryRow> getRows() { return rows; }
}
