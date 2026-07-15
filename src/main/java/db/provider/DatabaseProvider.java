package db.provider;

import java.sql.Connection;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Statement;

/**
 * Abstraction over the underlying database engine.
 * Callers always go through DatabaseConnection.getConnection() — they never
 * talk to a provider directly.
 */
public interface DatabaseProvider extends AutoCloseable {

    /** Return a live connection from the pool. Caller must close() it. */
    Connection getConnection() throws SQLException;

    /**
     * Called once after the provider is created.
     * Runs pending schema migrations.
     */
    void initialize() throws SQLException;

    /** Drain the pool and release all resources. */
    @Override
    void close();

    /** "sqlite" | "mysql" | "mariadb" */
    String getType();

    /** True if the provider can successfully return a connection right now. */
    default boolean testConnection() {
        try (Connection c = getConnection()) {
            return c != null && !c.isClosed();
        } catch (Exception e) {
            return false;
        }
    }

    /**
     * True when this provider's database already holds SusuApp data — used
     * by the setup and engine-switch wizards to warn before overwriting.
     * local_users is the right table to check (a completed setup always
     * creates the first admin there). A missing table means a genuinely
     * fresh, un-migrated database (false); any other failure is rethrown so
     * a broken connection is never mistaken for an empty database.
     */
    default boolean hasExistingData() throws SQLException {
        try (Connection conn = getConnection();
             Statement stmt = conn.createStatement();
             ResultSet rs = stmt.executeQuery("SELECT COUNT(*) FROM local_users")) {
            return rs.next() && rs.getInt(1) > 0;
        } catch (SQLException e) {
            String msg = e.getMessage() == null ? "" : e.getMessage().toLowerCase();
            if (msg.contains("doesn't exist") || msg.contains("no such table")
                    || msg.contains("unknown table")) {
                return false;
            }
            throw e;
        }
    }
}
