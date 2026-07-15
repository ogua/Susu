package service;

import db.AppConfig;
import db.DatabaseConnection;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.UUID;
import java.util.logging.Level;
import java.util.logging.Logger;
import models.LocalUser;
import org.mindrot.jbcrypt.BCrypt;

/**
 * Local authentication against the local_users table (bcrypt + login lockout,
 * mirroring the Oguaschoolz V018 pattern). Works identically in standalone
 * and hybrid modes; hybrid additionally refreshes these rows from the server
 * when online (Phase 1 SyncService).
 */
public class AuthService {

    private static final Logger LOGGER = Logger.getLogger(AuthService.class.getName());
    private static final int MAX_FAILED_ATTEMPTS = 5;
    private static final int LOCKOUT_MINUTES = 15;

    private final ApiClient apiClient = new ApiClient();

    /** Result of a login attempt; user is non-null only on SUCCESS. */
    public record LoginResult(Status status, LocalUser user, String message) {
        public enum Status { SUCCESS, INVALID, LOCKED, INACTIVE, ERROR }
    }

    public LoginResult login(String email, String password) {
        String sql = "SELECT * FROM local_users WHERE email = ?";
        String id, lockedUntil, passwordHash, serverUserId, name, phone, role;
        int isActive, failedAttempts;
        // The row is fully read out before the connection/ResultSet close below —
        // registerFailedAttempt/clearFailedAttempts each acquire their own connection,
        // and the SQLite pool is single-connection, so calling them while this one is
        // still open would have the thread wait on itself until Hikari's 30s timeout.
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(sql)) {
            ps.setString(1, email.trim().toLowerCase());
            try (ResultSet rs = ps.executeQuery()) {
                if (!rs.next()) {
                    return new LoginResult(LoginResult.Status.INVALID, null, "Invalid email or password.");
                }
                id = rs.getString("id");
                lockedUntil = rs.getString("locked_until");
                passwordHash = rs.getString("password_hash");
                isActive = rs.getInt("is_active");
                failedAttempts = rs.getInt("failed_attempts");
                serverUserId = rs.getString("server_user_id");
                name = rs.getString("name");
                phone = rs.getString("phone");
                role = rs.getString("role");
            }
        } catch (SQLException e) {
            LOGGER.log(Level.SEVERE, "Login failed: {0}", e.getMessage());
            return new LoginResult(LoginResult.Status.ERROR, null, "Could not reach the local database.");
        }

        if (lockedUntil != null && Instant.parse(lockedUntil).isAfter(Instant.now())) {
            return new LoginResult(LoginResult.Status.LOCKED, null,
                    "Account locked after repeated failures. Try again later.");
        }

        if (!BCrypt.checkpw(password, passwordHash)) {
            registerFailedAttempt(id, failedAttempts);
            return new LoginResult(LoginResult.Status.INVALID, null, "Invalid email or password.");
        }

        if (isActive == 0) {
            return new LoginResult(LoginResult.Status.INACTIVE, null, "This account has been deactivated.");
        }

        clearFailedAttempts(id);
        LocalUser user = new LocalUser(id, serverUserId, name, email, phone, role, true);
        refreshApiTokenIfHybrid(email, password);
        return new LoginResult(LoginResult.Status.SUCCESS, user, null);
    }

    /** Create a local user (setup wizard / user management). Returns the new id. */
    public String createUser(String name, String email, String phone, String role, String password)
            throws SQLException {
        String id = UUID.randomUUID().toString();
        String now = Instant.now().toString();
        String sql = "INSERT INTO local_users (id, name, email, phone, role, password_hash,"
                + " is_active, failed_attempts, created_at, updated_at)"
                + " VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, ?)";
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(sql)) {
            ps.setString(1, id);
            ps.setString(2, name);
            ps.setString(3, email.trim().toLowerCase());
            ps.setString(4, phone);
            ps.setString(5, role);
            ps.setString(6, BCrypt.hashpw(password, BCrypt.gensalt()));
            ps.setString(7, now);
            ps.setString(8, now);
            ps.executeUpdate();
        }
        return id;
    }

    public boolean hasAnyUser() throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT COUNT(*) FROM local_users");
             ResultSet rs = ps.executeQuery()) {
            return rs.next() && rs.getInt(1) > 0;
        }
    }

    /**
     * Hybrid mode only: best-effort background attempt to (re)authenticate with the
     * server and cache a fresh Sanctum token, so the outbox can push once online.
     * Fire-and-forget — never delays or fails the local login, which already
     * succeeded above.
     */
    private void refreshApiTokenIfHybrid(String email, String password) {
        if (!AppConfig.isSyncEnabled()) {
            return;
        }

        Thread thread = new Thread(() -> {
            try {
                apiClient.login(email.trim().toLowerCase(), password, "susudesktop");
            } catch (ApiClient.ApiException e) {
                LOGGER.log(Level.INFO, "Online token refresh skipped: {0}", e.getMessage());
            }
        }, "api-token-refresh");
        thread.setDaemon(true);
        thread.start();
    }

    private void registerFailedAttempt(String userId, int currentFailures) {
        int failures = currentFailures + 1;
        String lockedUntil = failures >= MAX_FAILED_ATTEMPTS
                ? Instant.now().plus(LOCKOUT_MINUTES, ChronoUnit.MINUTES).toString()
                : null;
        String sql = "UPDATE local_users SET failed_attempts = ?, locked_until = ?, updated_at = ? WHERE id = ?";
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(sql)) {
            ps.setInt(1, failures >= MAX_FAILED_ATTEMPTS ? 0 : failures);
            ps.setString(2, lockedUntil);
            ps.setString(3, Instant.now().toString());
            ps.setString(4, userId);
            ps.executeUpdate();
        } catch (SQLException e) {
            LOGGER.log(Level.WARNING, "Could not record failed attempt: {0}", e.getMessage());
        }
    }

    private void clearFailedAttempts(String userId) {
        String sql = "UPDATE local_users SET failed_attempts = 0, locked_until = NULL, updated_at = ? WHERE id = ?";
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(sql)) {
            ps.setString(1, Instant.now().toString());
            ps.setString(2, userId);
            ps.executeUpdate();
        } catch (SQLException e) {
            LOGGER.log(Level.WARNING, "Could not clear failed attempts: {0}", e.getMessage());
        }
    }
}
