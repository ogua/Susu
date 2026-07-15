package service;

import db.DatabaseConnection;
import enums.LoanStatus;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.util.ArrayList;
import java.util.List;
import org.json.JSONObject;

/**
 * One-time backfill for a standalone company deciding to "go online" (G5):
 * walks every locally-recorded row this app's sync protocol already knows
 * how to replay and queues it into the outbox, then lets the existing
 * {@link SyncService} drain it exactly like ordinary hybrid-mode sync. Every
 * payload reuses the row's own id/client_reference as the sync
 * client_reference (the same "client_reference-as-id" convention the live
 * create paths already use), so a partially-failed or re-run backfill is
 * naturally idempotent rather than double-posting.
 *
 * <p>Each query below fully drains its ResultSet into memory and closes its
 * connection <b>before</b> calling {@link OutboxService#enqueue} (which
 * opens its own connection) — the SQLite pool is single-connection, so
 * enqueuing from inside an open SELECT's try-with-resources block would
 * self-deadlock.</p>
 *
 * <p><b>Known gaps</b> — the backend's {@code SyncOpType} enum doesn't cover
 * these yet, so this backfill cannot replay them: withdrawal requests, group
 * creation/activation, and group payouts. A company with meaningful history
 * there needs a manual reconciliation pass after going online.</p>
 *
 * <p><b>Actor attribution</b> — {@code loan.approve}/{@code reject}/
 * {@code disburse} and {@code summary.submit} carry no historical-actor
 * field of their own; the backend resolves "who did this" from the Sanctum
 * token submitting the sync batch. Every backfilled op is therefore
 * attributed to whoever runs this migration, not the original agent/
 * manager. Financial amounts and timestamps are preserved exactly — only
 * that attribution is affected.</p>
 */
public class GoOnlineService {

    private final OutboxService outbox = new OutboxService();

    public record BackfillResult(String label, int queued, int skipped) {}

    private record Op(String type, JSONObject payload) {}

    public List<BackfillResult> backfillOutbox() throws SQLException {
        List<BackfillResult> results = new ArrayList<>();
        results.add(backfillCustomers());
        results.add(backfillAccounts());
        results.add(backfillCollections());
        results.add(backfillLoanLifecycle());
        results.add(backfillLoanRepayments());
        results.add(backfillGroupContributions());
        return results;
    }

    private BackfillResult backfillCustomers() throws SQLException {
        List<Op> ops = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM customers ORDER BY created_at");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                ops.add(new Op("customer.register", new JSONObject()
                        .put("first_name", rs.getString("first_name"))
                        .put("last_name", rs.getString("last_name"))
                        .put("phone", rs.getString("phone"))
                        .put("gender", rs.getString("gender"))
                        .put("date_of_birth", rs.getString("date_of_birth"))
                        .put("id_type", rs.getString("id_type"))
                        .put("id_number", rs.getString("id_number"))
                        .put("next_of_kin_name", rs.getString("next_of_kin_name"))
                        .put("next_of_kin_phone", rs.getString("next_of_kin_phone"))
                        .put("next_of_kin_relationship", rs.getString("next_of_kin_relationship"))
                        .put("address", rs.getString("address"))
                        .put("client_reference", rs.getString("id"))));
            }
        }
        enqueueAll(ops);
        return new BackfillResult("customer.register", ops.size(), 0);
    }

    private BackfillResult backfillAccounts() throws SQLException {
        List<Op> ops = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM savings_accounts ORDER BY opened_at");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                JSONObject payload = new JSONObject()
                        .put("customer_id", rs.getString("customer_id"))
                        .put("savings_product_id", rs.getString("savings_product_id"))
                        .put("client_reference", rs.getString("id"))
                        .put("contribution_amount", rs.getLong("contribution_amount"));
                long targetAmount = rs.getLong("target_amount");
                if (!rs.wasNull()) {
                    payload.put("target_amount", targetAmount);
                    payload.put("matures_at", rs.getString("matures_at"));
                }
                ops.add(new Op("account.open", payload));
            }
        }
        enqueueAll(ops);
        return new BackfillResult("account.open", ops.size(), 0);
    }

    /** Only 'collection' entries — 'commission' entries are re-derived server-side from these. */
    private BackfillResult backfillCollections() throws SQLException {
        List<Op> ops = new ArrayList<>();
        int skipped = 0;
        String sql = "SELECT je.client_reference, je.recorded_at,"
                + " (SELECT credit FROM journal_lines WHERE journal_entry_id = je.id AND credit > 0 LIMIT 1) AS amount,"
                + " (SELECT sa.id FROM journal_lines jl JOIN savings_accounts sa"
                + "   ON sa.ledger_account_id = jl.ledger_account_id"
                + "  WHERE jl.journal_entry_id = je.id AND jl.credit > 0 LIMIT 1) AS savings_account_id"
                + " FROM journal_entries je WHERE je.type = 'collection' ORDER BY je.recorded_at";
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(sql);
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                String accountId = rs.getString("savings_account_id");
                if (accountId == null) {
                    skipped++;
                    continue;
                }
                ops.add(new Op("collection.record", new JSONObject()
                        .put("savings_account_id", accountId)
                        .put("amount", rs.getLong("amount"))
                        .put("client_reference", rs.getString("client_reference"))
                        .put("recorded_at", rs.getString("recorded_at"))));
            }
        }
        enqueueAll(ops);
        return new BackfillResult("collection.record", ops.size(), skipped);
    }

    private BackfillResult backfillLoanLifecycle() throws SQLException {
        List<Op> ops = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM loans ORDER BY applied_at");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                String loanId = rs.getString("id");
                LoanStatus status = LoanStatus.fromValue(rs.getString("status"));

                ops.add(new Op("loan.apply", new JSONObject()
                        .put("customer_id", rs.getString("customer_id"))
                        .put("loan_product_id", rs.getString("loan_product_id"))
                        .put("amount", rs.getLong("principal_amount"))
                        .put("savings_account_id", rs.getString("savings_account_id"))
                        .put("guarantor_name", rs.getString("guarantor_name"))
                        .put("guarantor_phone", rs.getString("guarantor_phone"))
                        .put("notes", rs.getString("notes"))
                        .put("client_reference", loanId)));

                if (status == LoanStatus.REJECTED) {
                    ops.add(new Op("loan.reject", new JSONObject()
                            .put("loan_id", loanId)
                            .put("reason", rs.getString("rejection_reason"))));
                    continue;
                }

                if (rs.getString("approved_at") != null) {
                    ops.add(new Op("loan.approve", new JSONObject().put("loan_id", loanId)));
                }
                if (rs.getString("disbursed_at") != null) {
                    ops.add(new Op("loan.disburse", new JSONObject().put("loan_id", loanId)));
                }
            }
        }
        enqueueAll(ops);
        return new BackfillResult("loan.apply/approve/reject/disburse", ops.size(), 0);
    }

    /**
     * loan_id is recovered from the repayment's principal credit line against
     * loans.receivable_account_id — a repayment applied entirely to interest/
     * penalty (no principal portion) has no such line and is skipped, since
     * there's no reliable way to recover which loan it belongs to from the
     * journal alone.
     */
    private BackfillResult backfillLoanRepayments() throws SQLException {
        List<Op> ops = new ArrayList<>();
        int skipped = 0;
        String sql = "SELECT je.client_reference, je.recorded_at,"
                + " (SELECT debit FROM journal_lines WHERE journal_entry_id = je.id AND debit > 0 LIMIT 1) AS amount,"
                + " (SELECT l.id FROM journal_lines jl JOIN loans l ON l.receivable_account_id = jl.ledger_account_id"
                + "  WHERE jl.journal_entry_id = je.id AND jl.credit > 0 LIMIT 1) AS loan_id"
                + " FROM journal_entries je WHERE je.type = 'repayment' ORDER BY je.recorded_at";
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(sql);
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                String loanId = rs.getString("loan_id");
                if (loanId == null) {
                    skipped++;
                    continue;
                }
                ops.add(new Op("loan.repayment.record", new JSONObject()
                        .put("loan_id", loanId)
                        .put("amount", rs.getLong("amount"))
                        .put("client_reference", rs.getString("client_reference"))
                        .put("recorded_at", rs.getString("recorded_at"))));
            }
        }
        enqueueAll(ops);
        return new BackfillResult("loan.repayment.record", ops.size(), skipped);
    }

    private BackfillResult backfillGroupContributions() throws SQLException {
        List<Op> ops = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM group_contributions ORDER BY recorded_at");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                ops.add(new Op("group.contribution.record", new JSONObject()
                        .put("group_member_id", rs.getString("group_member_id"))
                        .put("client_reference", rs.getString("client_reference"))));
            }
        }
        enqueueAll(ops);
        return new BackfillResult("group.contribution.record", ops.size(), 0);
    }

    private void enqueueAll(List<Op> ops) throws SQLException {
        for (Op op : ops) {
            outbox.enqueue(op.type(), op.payload());
        }
    }
}
