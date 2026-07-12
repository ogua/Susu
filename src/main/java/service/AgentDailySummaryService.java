package service;

import db.DatabaseConnection;
import enums.AgentSummaryStatus;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.UUID;
import models.AgentDailySummary;
import org.json.JSONObject;

/**
 * Agent day sheets: running collection totals, cash reconciliation. Mirrors
 * App\Actions\Agents\{Submit,Reconcile}...Action — expected cash for
 * reconciliation is simply the agent's cash-in-hand ledger balance.
 */
public class AgentDailySummaryService {

    private final ChartOfAccounts chart = new ChartOfAccounts();
    private final OutboxService outbox = new OutboxService();

    /** Called by {@link CollectionService} after every collection — auto-opens today's sheet. */
    public void trackCollection(String agentId, long amount, Instant recordedAt) throws SQLException {
        String date = toDate(recordedAt);
        AgentDailySummary summary = findOrCreate(agentId, date);

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE agent_daily_summaries SET collections_total = collections_total + ?,"
                     + " collections_count = collections_count + 1, updated_at = ? WHERE id = ?")) {
            ps.setLong(1, amount);
            ps.setString(2, Instant.now().toString());
            ps.setString(3, summary.getId());
            ps.executeUpdate();
        }
    }

    public AgentDailySummary today(String agentId) throws SQLException {
        return findOrCreate(agentId, toDate(Instant.now()));
    }

    public long expectedCash(String agentId, String agentName) throws SQLException {
        return chart.agentCash(agentId, agentName).getBalance();
    }

    public AgentDailySummary submit(String agentId, String agentName, long declaredCash, String notes)
            throws SQLException {
        AgentDailySummary summary = today(agentId);
        if (summary.getStatus() == AgentSummaryStatus.RECONCILED) {
            throw new IllegalStateException("This day has already been reconciled.");
        }

        long expected = expectedCash(agentId, agentName);
        long variance = declaredCash - expected;

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE agent_daily_summaries SET expected_cash = ?, declared_cash = ?, variance = ?,"
                     + " status = ?, notes = ?, updated_at = ? WHERE id = ?")) {
            ps.setLong(1, expected);
            ps.setLong(2, declaredCash);
            ps.setLong(3, variance);
            ps.setString(4, AgentSummaryStatus.SUBMITTED.value());
            ps.setString(5, notes);
            ps.setString(6, Instant.now().toString());
            ps.setString(7, summary.getId());
            ps.executeUpdate();
        }

        outbox.enqueueIfHybrid("summary.submit", new JSONObject()
                .put("declared_cash", declaredCash)
                .put("summary_date", summary.getSummaryDate())
                .put("notes", notes)
                .put("client_reference", UUID.randomUUID().toString()));

        return findById(summary.getId());
    }

    public AgentDailySummary reconcile(String summaryId, String reconciledBy, boolean flag, String notes)
            throws SQLException {
        AgentDailySummary summary = findById(summaryId);
        if (summary.getStatus() != AgentSummaryStatus.SUBMITTED) {
            throw new IllegalStateException("Only submitted day sheets can be reconciled.");
        }

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE agent_daily_summaries SET status = ?, reconciled_by = ?, notes = ?, updated_at = ?"
                     + " WHERE id = ?")) {
            ps.setString(1, (flag ? AgentSummaryStatus.FLAGGED : AgentSummaryStatus.RECONCILED).value());
            ps.setString(2, reconciledBy);
            ps.setString(3, notes != null ? notes : summary.getNotes());
            ps.setString(4, Instant.now().toString());
            ps.setString(5, summaryId);
            ps.executeUpdate();
        }

        return findById(summaryId);
    }

    private AgentDailySummary findOrCreate(String agentId, String date) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection()) {
            try (PreparedStatement ps = conn.prepareStatement(
                    "SELECT * FROM agent_daily_summaries WHERE agent_id = ? AND summary_date = ?")) {
                ps.setString(1, agentId);
                ps.setString(2, date);
                try (ResultSet rs = ps.executeQuery()) {
                    if (rs.next()) {
                        return map(rs);
                    }
                }
            }

            String id = UUID.randomUUID().toString();
            String now = Instant.now().toString();
            try (PreparedStatement ps = conn.prepareStatement(
                    "INSERT INTO agent_daily_summaries (id, agent_id, summary_date, status, created_at, updated_at)"
                    + " VALUES (?,?,?,?,?,?)")) {
                ps.setString(1, id);
                ps.setString(2, agentId);
                ps.setString(3, date);
                ps.setString(4, AgentSummaryStatus.OPEN.value());
                ps.setString(5, now);
                ps.setString(6, now);
                ps.executeUpdate();
            }

            return findById(conn, id);
        }
    }

    public AgentDailySummary findById(String id) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection()) {
            return findById(conn, id);
        }
    }

    private AgentDailySummary findById(Connection conn, String id) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement("SELECT * FROM agent_daily_summaries WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? map(rs) : null;
            }
        }
    }

    private String toDate(Instant instant) {
        return LocalDate.ofInstant(instant, ZoneId.systemDefault()).format(DateTimeFormatter.ISO_LOCAL_DATE);
    }

    private AgentDailySummary map(ResultSet rs) throws SQLException {
        AgentDailySummary summary = new AgentDailySummary();
        summary.setId(rs.getString("id"));
        summary.setAgentId(rs.getString("agent_id"));
        summary.setSummaryDate(rs.getString("summary_date"));
        summary.setCollectionsTotal(rs.getLong("collections_total"));
        summary.setCollectionsCount(rs.getInt("collections_count"));
        summary.setExpectedCash(rs.getLong("expected_cash"));
        long declared = rs.getLong("declared_cash");
        summary.setDeclaredCash(rs.wasNull() ? null : declared);
        long variance = rs.getLong("variance");
        summary.setVariance(rs.wasNull() ? null : variance);
        summary.setStatus(AgentSummaryStatus.fromValue(rs.getString("status")));
        summary.setReconciledBy(rs.getString("reconciled_by"));
        summary.setNotes(rs.getString("notes"));
        return summary;
    }
}
