package db.provider;

import com.zaxxer.hikari.HikariConfig;
import com.zaxxer.hikari.HikariDataSource;
import db.AppConfig;
import db.migration.MigrationRunner;
import java.sql.Connection;
import java.sql.SQLException;
import java.util.logging.Logger;

/**
 * SQLite provider for offline / single-machine installations.
 * Database file lives at ~/.susudesktop/susuapp.db.
 * HikariCP pool size is capped at 1 — SQLite allows only one writer at a time.
 */
public class SQLiteProvider implements DatabaseProvider {

    private static final Logger LOGGER = Logger.getLogger(SQLiteProvider.class.getName());

    private final HikariDataSource dataSource;

    public SQLiteProvider() {
        // Ensure the AppConfig has SQLite defaults before we try to connect
        if (!"sqlite".equalsIgnoreCase(AppConfig.get("db.type", ""))) {
            AppConfig.applySQLiteDefaults();
        }

        this.dataSource = buildDataSource(AppConfig.get("db.url"));
    }

    /**
     * Construct a provider against an explicit JDBC URL — used by the
     * engine-migration wizard to probe/populate a target SQLite file without
     * touching AppConfig until the copy has already succeeded.
     */
    public SQLiteProvider(String jdbcUrl) {
        this.dataSource = buildDataSource(jdbcUrl);
    }

    private static HikariDataSource buildDataSource(String rawUrl) {
        // Embed WAL mode and busy_timeout directly in the URL — this is the only
        // reliable path when HikariCP uses driverClassName (DriverManager mode).
        // addDataSourceProperty() is silently ignored in this mode for SQLite JDBC.
        String jdbcUrl = rawUrl.contains("journal_mode=")
                ? rawUrl
                : rawUrl + (rawUrl.contains("?") ? "&" : "?")
                  + "journal_mode=WAL&busy_timeout=30000&foreign_keys=on";

        HikariConfig config = new HikariConfig();
        config.setJdbcUrl(jdbcUrl);
        config.setDriverClassName("org.sqlite.JDBC");
        config.setMaximumPoolSize(1);
        config.setConnectionTestQuery("SELECT 1");
        // Allow callers to queue for up to 30 s before HikariCP throws
        config.setConnectionTimeout(30_000);
        config.setPoolName("SusuSQLite");

        HikariDataSource dataSource = new HikariDataSource(config);
        LOGGER.info("SQLiteProvider pool created.");
        return dataSource;
    }

    @Override
    public Connection getConnection() throws SQLException {
        return dataSource.getConnection();
    }

    /** Runs pending SQL migrations against the SQLite database. */
    @Override
    public void initialize() throws SQLException {
        new MigrationRunner(this).run();
    }

    @Override
    public void close() {
        if (dataSource != null && !dataSource.isClosed()) {
            dataSource.close();
            LOGGER.info("SQLiteProvider pool closed.");
        }
    }

    @Override
    public String getType() {
        return "sqlite";
    }
}
