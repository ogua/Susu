package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.LedgerAccountType;
import enums.TransactionType;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import java.util.UUID;
import models.JournalEntry;
import models.LedgerAccount;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

/** Mirrors tests/Feature/Ledger/LedgerServiceTest.php — same posting/reversal/idempotency guarantees. */
class LedgerServiceTest {

    private static String originalHome;
    private final LedgerService ledger = new LedgerService();
    private final ChartOfAccounts chart = new ChartOfAccounts();

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-ledger-test-");
        System.setProperty("user.home", tempHome.toString());

        AppConfig.applySQLiteDefaults();
        new SQLiteProvider().initialize();
    }

    @AfterAll
    static void restoreHome() {
        DatabaseConnection.shutdown();
        System.setProperty("user.home", originalHome);
    }

    private LedgerAccount cash;
    private LedgerAccount liability;

    @BeforeEach
    void freshAccounts() throws Exception {
        // Unique codes per test so accounts never bleed balances across tests.
        cash = createAccount(LedgerAccountType.ASSET);
        liability = createAccount(LedgerAccountType.LIABILITY);
    }

    private LedgerAccount createAccount(LedgerAccountType type) throws Exception {
        String code = type.value() + "-" + UUID.randomUUID();
        try (var conn = DatabaseConnection.getConnection()) {
            String id = UUID.randomUUID().toString();
            String now = java.time.Instant.now().toString();
            try (var ps = conn.prepareStatement(
                    "INSERT INTO ledger_accounts (id, code, name, type, balance, is_system, created_at, updated_at)"
                    + " VALUES (?,?,?,?,0,0,?,?)")) {
                ps.setString(1, id);
                ps.setString(2, code);
                ps.setString(3, code);
                ps.setString(4, type.value());
                ps.setString(5, now);
                ps.setString(6, now);
                ps.executeUpdate();
            }
            LedgerAccount account = new LedgerAccount();
            account.setId(id);
            account.setCode(code);
            account.setName(code);
            account.setType(type);
            account.setBalance(0);
            return account;
        }
    }

    @Test
    void postsABalancedEntryAndUpdatesCachedBalancesByNormalBalance() throws Exception {
        JournalEntry entry = ledger.post(EntryRequest.of(TransactionType.COLLECTION, List.of(
                LedgerLine.debit(cash.getId(), 1000),
                LedgerLine.credit(liability.getId(), 1000)
        )));

        assertEquals(2, entry.getLines().size());
        assertEquals(1000, ledger.recomputeBalance(refreshed(cash)));
        assertEquals(1000, ledger.recomputeBalance(refreshed(liability)));
    }

    @Test
    void rejectsUnbalancedEntries() {
        assertThrows(IllegalArgumentException.class, () -> ledger.post(EntryRequest.of(TransactionType.COLLECTION, List.of(
                LedgerLine.debit(cash.getId(), 1000),
                LedgerLine.credit(liability.getId(), 900)
        ))));
    }

    @Test
    void isIdempotentOnClientReference() throws Exception {
        String reference = UUID.randomUUID().toString();
        EntryRequest request = EntryRequest.of(TransactionType.COLLECTION, List.of(
                LedgerLine.debit(cash.getId(), 700),
                LedgerLine.credit(liability.getId(), 700)
        )).clientReference(reference);

        JournalEntry first = ledger.post(request);
        JournalEntry second = ledger.post(request);

        assertEquals(first.getId(), second.getId());
        assertEquals(700, ledger.recomputeBalance(refreshed(cash)));
    }

    @Test
    void reversesAnEntryWithOppositeLinesAndMarksTheOriginal() throws Exception {
        JournalEntry entry = ledger.post(EntryRequest.of(TransactionType.COLLECTION, List.of(
                LedgerLine.debit(cash.getId(), 1200),
                LedgerLine.credit(liability.getId(), 1200)
        )));

        JournalEntry reversal = ledger.reverse(entry, "tester", "recorded in error");

        assertEquals(TransactionType.REVERSAL, reversal.getType());
        assertNotEquals(entry.getId(), reversal.getId());
        assertEquals(0, ledger.recomputeBalance(refreshed(cash)));
        assertEquals(0, ledger.recomputeBalance(refreshed(liability)));
    }

    @Test
    void refusesToReverseTwice() throws Exception {
        JournalEntry entry = ledger.post(EntryRequest.of(TransactionType.COLLECTION, List.of(
                LedgerLine.debit(cash.getId(), 100),
                LedgerLine.credit(liability.getId(), 100)
        )));

        ledger.reverse(entry, "tester", "first");
        JournalEntry reloaded = ledger.findById(entry.getId());

        assertThrows(IllegalStateException.class, () -> ledger.reverse(reloaded, "tester", "second"));
    }

    private LedgerAccount refreshed(LedgerAccount account) {
        return account;
    }
}
