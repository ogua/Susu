package service;

/** One leg of a journal entry — exactly one of debit/credit must be non-zero. */
public record LedgerLine(String ledgerAccountId, long debit, long credit, String memo) {

    public static LedgerLine debit(String ledgerAccountId, long amount) {
        return new LedgerLine(ledgerAccountId, amount, 0, null);
    }

    public static LedgerLine debit(String ledgerAccountId, long amount, String memo) {
        return new LedgerLine(ledgerAccountId, amount, 0, memo);
    }

    public static LedgerLine credit(String ledgerAccountId, long amount) {
        return new LedgerLine(ledgerAccountId, 0, amount, null);
    }

    public static LedgerLine credit(String ledgerAccountId, long amount, String memo) {
        return new LedgerLine(ledgerAccountId, 0, amount, memo);
    }
}
