package db;

import db.provider.DatabaseProvider;
import db.provider.ProviderFactory;
import java.sql.Connection;
import java.sql.SQLException;
import java.util.logging.Level;
import java.util.logging.Logger;

/**
 * Thin facade over the active DatabaseProvider.
 *
 * All application code obtains connections through getConnection().
 * The underlying engine (SQLite or MySQL) is transparent to callers.
 */
public class DatabaseConnection {

    private static final Logger LOGGER = Logger.getLogger(DatabaseConnection.class.getName());
    private static volatile DatabaseProvider provider;

    private DatabaseConnection() {}

    public static Connection getConnection() throws SQLException {
        return getProvider().getConnection();
    }

    /** Drain the connection pool. Call on application shutdown. */
    public static void shutdown() {
        if (provider != null) {
            synchronized (DatabaseConnection.class) {
                if (provider != null) {
                    provider.close();
                    provider = null;
                    LOGGER.info("DatabaseConnection shut down.");
                }
            }
        }
    }

    /**
     * Returns the currently active provider, initializing it if necessary.
     * Used by features (e.g. Backup &amp; Restore) that need to pass a
     * {@link DatabaseProvider} to code outside the {@code db} package.
     */
    public static DatabaseProvider getActiveProvider() {
        return getProvider();
    }

    /**
     * Replaces the active provider — used by the setup wizard after the user
     * changes database settings. Shuts down the old pool first.
     */
    public static void resetProvider() {
        synchronized (DatabaseConnection.class) {
            if (provider != null) {
                provider.close();
            }
            provider = null;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    static DatabaseProvider getProvider() {
        if (provider == null) {
            synchronized (DatabaseConnection.class) {
                if (provider == null) {
                    provider = ProviderFactory.create();
                    try {
                        provider.initialize();
                    } catch (SQLException e) {
                        LOGGER.log(Level.SEVERE, "Provider initialization failed: {0}", e.getMessage());
                        provider.close();
                        provider = null;
                        throw new RuntimeException(
                                "Database initialization failed. "
                                + "Check your settings in ~/.susudesktop/config.properties\n\n"
                                + e.getMessage(), e);
                    }
                }
            }
        }
        return provider;
    }
}
