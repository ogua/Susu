package service;

import db.AppConfig;
import db.DatabaseConnection;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import org.json.JSONObject;

/**
 * Local outbox: financial/master-data ops recorded here await replay against
 * POST /api/v1/sync/batch (AD-3). Mirrors the mobile app's src/sync/outbox.ts
 * — same op envelope, same statuses (pending/synced/rejected) — so both
 * clients drain identically against the same server contract.
 */
public class OutboxService {

    public record OutboxItem(String opId, String opType, String payload, String recordedAt,
                              String status, int attempts, String lastError) {}

    /** No-op in standalone mode; enqueues in hybrid mode. Payload should already carry client_reference. */
    public void enqueueIfHybrid(String opType, JSONObject payload) throws SQLException {
        if (!AppConfig.isSyncEnabled()) {
            return;
        }
        enqueue(opType, payload);
    }

    /**
     * Enqueues unconditionally, regardless of {@code sync.enabled} — used by
     * {@link GoOnlineService} while backfilling a standalone company's
     * history, since that runs before (or right as) hybrid mode is turned on.
     */
    public void enqueue(String opType, JSONObject payload) throws SQLException {
        String opId = UUID.randomUUID().toString();
        String now = Instant.now().toString();

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "INSERT INTO outbox (op_id, op_type, payload, recorded_at, status, attempts, created_at)"
                     + " VALUES (?,?,?,?,'pending',0,?)")) {
            ps.setString(1, opId);
            ps.setString(2, opType);
            ps.setString(3, payload.toString());
            ps.setString(4, now);
            ps.setString(5, now);
            ps.executeUpdate();
        }
    }

    public List<OutboxItem> pending(int limit) throws SQLException {
        List<OutboxItem> items = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM outbox WHERE status = 'pending' ORDER BY created_at LIMIT ?")) {
            ps.setInt(1, limit);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    items.add(map(rs));
                }
            }
        }
        return items;
    }

    public int pendingCount() throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT COUNT(*) FROM outbox WHERE status = 'pending'");
             ResultSet rs = ps.executeQuery()) {
            rs.next();
            return rs.getInt(1);
        }
    }

    public void markSynced(String opId) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE outbox SET status = 'synced' WHERE op_id = ?")) {
            ps.setString(1, opId);
            ps.executeUpdate();
        }
    }

    public void markRejected(String opId, String error) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE outbox SET status = 'rejected', last_error = ?, attempts = attempts + 1 WHERE op_id = ?")) {
            ps.setString(1, error);
            ps.setString(2, opId);
            ps.executeUpdate();
        }
    }

    public void bumpAttempt(String opId, String error) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE outbox SET attempts = attempts + 1, last_error = ? WHERE op_id = ?")) {
            ps.setString(1, error);
            ps.setString(2, opId);
            ps.executeUpdate();
        }
    }

    private OutboxItem map(ResultSet rs) throws SQLException {
        return new OutboxItem(
                rs.getString("op_id"),
                rs.getString("op_type"),
                rs.getString("payload"),
                rs.getString("recorded_at"),
                rs.getString("status"),
                rs.getInt("attempts"),
                rs.getString("last_error"));
    }
}
