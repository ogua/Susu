package db.provider;

import java.sql.Connection;
import java.sql.SQLException;

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
}
