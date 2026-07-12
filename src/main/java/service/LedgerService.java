package service;

import db.DatabaseConnection;
import enums.EntryStatus;
import enums.LedgerAccountType;
import enums.PaymentMethod;
import enums.TransactionType;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import models.JournalEntry;
import models.JournalLine;
import models.LedgerAccount;

/**
 * The only write path into the double-entry ledger — the Java mirror of the
 * backend's {@code App\Services\Ledger\LedgerService}. Entries are
 * append-only: corrections are reversal entries, never edits. Cached account
 * balances are updated in the same transaction as the lines, under row locks
 * (MySQL) or SQLite's single-writer guarantee, so balance == Σ(lines) always
 * holds.
 *
 * <p>This class — together with {@link ChartOfAccounts} and
 * {@link CommissionCalculator} — is the standalone-mode operations engine
 * (AD-5): a disciplined, version-stamped parity port of the corresponding
 * Laravel Actions, not a fork. When the server-side rule changes, this must
 * change in the same session.</p>
 */
public class LedgerService {

    public static final String ENGINE_VERSION = "1.0.0";

    public JournalEntry findByClientReference(String clientReference) throws SQLException {
        if (clientReference == null) {
            return null;
        }
        try (Connection conn = DatabaseConnection.getConnection()) {
            return findByClientReference(conn, clientReference);
        }
    }

    public JournalEntry post(EntryRequest request) throws SQLException {
        assertBalanced(request);

        if (request.getClientReference() != null) {
            JournalEntry existing = findByClientReference(request.getClientReference());
            if (existing != null) {
                return existing;
            }
        }

        try (Connection conn = DatabaseConnection.getConnection()) {
            boolean autoCommit = conn.getAutoCommit();
            conn.setAutoCommit(false);
            try {
                List<String> accountIds = new ArrayList<>();
                for (LedgerLine line : request.getLines()) {
                    if (!accountIds.contains(line.ledgerAccountId())) {
                        accountIds.add(line.ledgerAccountId());
                    }
                }
                Collections.sort(accountIds); // deterministic lock order avoids MySQL deadlocks

                Map<String, LedgerAccount> accounts = lockAccounts(conn, accountIds);

                String entryId = UUID.randomUUID().toString();
                Instant now = Instant.now();
                Instant recordedAt = request.getRecordedAt() != null ? request.getRecordedAt() : now;

                insertEntry(conn, entryId, generateReference(), request, recordedAt, now);

                for (LedgerLine line : request.getLines()) {
                    insertLine(conn, entryId, line, now);
                    LedgerAccount account = accounts.get(line.ledgerAccountId());
                    long delta = account.getType().isNormalBalanceDebit()
                            ? line.debit() - line.credit()
                            : line.credit() - line.debit();
                    long newBalance = account.getBalance() + delta;
                    updateBalance(conn, account.getId(), newBalance, now);
                    account.setBalance(newBalance);
                }

                conn.commit();

                return findById(conn, entryId);
            } catch (Exception e) {
                conn.rollback();
                throw e;
            } finally {
                conn.setAutoCommit(autoCommit);
            }
        }
    }

    /** Posts an equal-and-opposite entry and marks the original reversed. */
    public JournalEntry reverse(JournalEntry entry, String reversedBy, String reason) throws SQLException {
        if (entry.getStatus() == EntryStatus.REVERSED) {
            throw new IllegalStateException("Entry has already been reversed.");
        }

        List<LedgerLine> reversedLines = new ArrayList<>();
        for (JournalLine line : entry.getLines()) {
            reversedLines.add(new LedgerLine(line.getLedgerAccountId(), line.getCredit(), line.getDebit(), "Reversal: " + reason));
        }

        JournalEntry reversal = post(EntryRequest.of(TransactionType.REVERSAL, reversedLines)
                .paymentMethod(PaymentMethod.INTERNAL)
                .origin("desktop")
                .recordedBy(reversedBy)
                .description(reason));

        try (Connection conn = DatabaseConnection.getConnection()) {
            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE journal_entries SET reversed_entry_id = ?, updated_at = ? WHERE id = ?")) {
                ps.setString(1, entry.getId());
                ps.setString(2, Instant.now().toString());
                ps.setString(3, reversal.getId());
                ps.executeUpdate();
            }
            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE journal_entries SET status = ?, updated_at = ? WHERE id = ?")) {
                ps.setString(1, EntryStatus.REVERSED.value());
                ps.setString(2, Instant.now().toString());
                ps.setString(3, entry.getId());
                ps.executeUpdate();
            }
        }

        return reversal;
    }

    /** Recomputes a balance from the lines — used for the integrity check and tests. */
    public long recomputeBalance(LedgerAccount account) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT COALESCE(SUM(debit),0) AS debits, COALESCE(SUM(credit),0) AS credits"
                     + " FROM journal_lines WHERE ledger_account_id = ?")) {
            ps.setString(1, account.getId());
            try (ResultSet rs = ps.executeQuery()) {
                rs.next();
                long debits = rs.getLong("debits");
                long credits = rs.getLong("credits");
                return account.getType().isNormalBalanceDebit() ? debits - credits : credits - debits;
            }
        }
    }

    public JournalEntry findById(String id) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection()) {
            return findById(conn, id);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private void assertBalanced(EntryRequest request) {
        if (request.getLines() == null || request.getLines().size() < 2) {
            throw new IllegalArgumentException("A journal entry needs at least two lines.");
        }

        long debits = 0;
        long credits = 0;
        for (LedgerLine line : request.getLines()) {
            if (line.debit() < 0 || line.credit() < 0) {
                throw new IllegalArgumentException("Line amounts must be non-negative.");
            }
            if ((line.debit() > 0) == (line.credit() > 0)) {
                throw new IllegalArgumentException("Each line must have exactly one non-zero side.");
            }
            debits += line.debit();
            credits += line.credit();
        }

        if (debits != credits || debits == 0) {
            throw new IllegalArgumentException(
                    "Entry is not balanced: debits " + debits + " vs credits " + credits + ".");
        }
    }

    private Map<String, LedgerAccount> lockAccounts(Connection conn, List<String> ids) throws SQLException {
        Map<String, LedgerAccount> result = new LinkedHashMap<>();
        boolean isMySQL = "mysql".equalsIgnoreCase(DatabaseConnection.getActiveProvider().getType());
        String sql = "SELECT * FROM ledger_accounts WHERE id = ?" + (isMySQL ? " FOR UPDATE" : "");

        for (String id : ids) {
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                try (ResultSet rs = ps.executeQuery()) {
                    if (!rs.next()) {
                        throw new SQLException("Ledger account not found: " + id);
                    }
                    result.put(id, mapAccount(rs));
                }
            }
        }
        return result;
    }

    private void insertEntry(Connection conn, String id, String reference, EntryRequest request,
                              Instant recordedAt, Instant now) throws SQLException {
        String sql = "INSERT INTO journal_entries (id, reference, client_reference, origin, type, status,"
                + " payment_method, description, recorded_by, recorded_at, posted_at, reversed_entry_id, meta,"
                + " created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        try (PreparedStatement ps = conn.prepareStatement(sql)) {
            ps.setString(1, id);
            ps.setString(2, reference);
            ps.setString(3, request.getClientReference());
            ps.setString(4, request.getOrigin());
            ps.setString(5, request.getType().value());
            ps.setString(6, EntryStatus.COMPLETED.value());
            ps.setString(7, request.getPaymentMethod().value());
            ps.setString(8, request.getDescription());
            ps.setString(9, request.getRecordedBy());
            ps.setString(10, recordedAt.toString());
            ps.setString(11, now.toString());
            ps.setString(12, null);
            ps.setString(13, request.getMeta());
            ps.setString(14, now.toString());
            ps.setString(15, now.toString());
            ps.executeUpdate();
        }
    }

    private void insertLine(Connection conn, String entryId, LedgerLine line, Instant now) throws SQLException {
        String sql = "INSERT INTO journal_lines (id, journal_entry_id, ledger_account_id, debit, credit, memo,"
                + " created_at) VALUES (?,?,?,?,?,?,?)";
        try (PreparedStatement ps = conn.prepareStatement(sql)) {
            ps.setString(1, UUID.randomUUID().toString());
            ps.setString(2, entryId);
            ps.setString(3, line.ledgerAccountId());
            ps.setLong(4, line.debit());
            ps.setLong(5, line.credit());
            ps.setString(6, line.memo());
            ps.setString(7, now.toString());
            ps.executeUpdate();
        }
    }

    private void updateBalance(Connection conn, String accountId, long newBalance, Instant now) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement(
                "UPDATE ledger_accounts SET balance = ?, updated_at = ? WHERE id = ?")) {
            ps.setLong(1, newBalance);
            ps.setString(2, now.toString());
            ps.setString(3, accountId);
            ps.executeUpdate();
        }
    }

    private String generateReference() {
        return "D" + Long.toString(Instant.now().toEpochMilli(), 36).toUpperCase()
                + Integer.toString((int) (Math.random() * 46656), 36).toUpperCase();
    }

    private JournalEntry findByClientReference(Connection conn, String clientReference) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement(
                "SELECT id FROM journal_entries WHERE client_reference = ?")) {
            ps.setString(1, clientReference);
            try (ResultSet rs = ps.executeQuery()) {
                if (!rs.next()) {
                    return null;
                }
                return findById(conn, rs.getString("id"));
            }
        }
    }

    private JournalEntry findById(Connection conn, String id) throws SQLException {
        JournalEntry entry;
        try (PreparedStatement ps = conn.prepareStatement("SELECT * FROM journal_entries WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                if (!rs.next()) {
                    return null;
                }
                entry = mapEntry(rs);
            }
        }

        List<JournalLine> lines = new ArrayList<>();
        try (PreparedStatement ps = conn.prepareStatement(
                "SELECT * FROM journal_lines WHERE journal_entry_id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    JournalLine line = new JournalLine();
                    line.setId(rs.getString("id"));
                    line.setJournalEntryId(rs.getString("journal_entry_id"));
                    line.setLedgerAccountId(rs.getString("ledger_account_id"));
                    line.setDebit(rs.getLong("debit"));
                    line.setCredit(rs.getLong("credit"));
                    line.setMemo(rs.getString("memo"));
                    lines.add(line);
                }
            }
        }
        entry.setLines(lines);
        return entry;
    }

    private JournalEntry mapEntry(ResultSet rs) throws SQLException {
        JournalEntry entry = new JournalEntry();
        entry.setId(rs.getString("id"));
        entry.setReference(rs.getString("reference"));
        entry.setClientReference(rs.getString("client_reference"));
        entry.setOrigin(rs.getString("origin"));
        entry.setType(TransactionType.fromValue(rs.getString("type")));
        entry.setStatus(EntryStatus.fromValue(rs.getString("status")));
        entry.setPaymentMethod(PaymentMethod.fromValue(rs.getString("payment_method")));
        entry.setDescription(rs.getString("description"));
        entry.setRecordedBy(rs.getString("recorded_by"));
        entry.setRecordedAt(Instant.parse(rs.getString("recorded_at")));
        entry.setPostedAt(Instant.parse(rs.getString("posted_at")));
        entry.setReversedEntryId(rs.getString("reversed_entry_id"));
        entry.setMeta(rs.getString("meta"));
        return entry;
    }

    private LedgerAccount mapAccount(ResultSet rs) throws SQLException {
        LedgerAccount account = new LedgerAccount();
        account.setId(rs.getString("id"));
        account.setCode(rs.getString("code"));
        account.setName(rs.getString("name"));
        account.setType(LedgerAccountType.fromValue(rs.getString("type")));
        account.setAccountableType(rs.getString("accountable_type"));
        account.setAccountableId(rs.getString("accountable_id"));
        account.setBalance(rs.getLong("balance"));
        account.setSystem(rs.getInt("is_system") != 0);
        return account;
    }
}
