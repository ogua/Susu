package db.provider;

import com.zaxxer.hikari.HikariConfig;
import com.zaxxer.hikari.HikariDataSource;
import db.AppConfig;
import db.migration.MigrationRunner;
import java.sql.Connection;
import java.sql.SQLException;
import java.util.logging.Logger;

/**
 * MySQL / MariaDB provider backed by a HikariCP connection pool — used for
 * multi-user LAN offices where several desktop clients share one server.
 * Configuration is read from AppConfig.
 */
public class MySQLProvider implements DatabaseProvider {

    private static final Logger LOGGER = Logger.getLogger(MySQLProvider.class.getName());

    private final HikariDataSource dataSource;

    public MySQLProvider() {
        this(
            AppConfig.get("db.url",
                "jdbc:mysql://127.0.0.1:3306/susuapp_desktop"
                + "?useSSL=false&allowPublicKeyRetrieval=true&serverTimezone=UTC"
                + "&zeroDateTimeBehavior=convertToNull"),
            AppConfig.get("db.username", "root"),
            AppConfig.get("db.password", "")
        );
    }

    /**
     * Construct a provider from explicit credentials — used by the setup and
     * engine-migration wizards to probe a target database without touching
     * AppConfig.
     */
    public MySQLProvider(String jdbcUrl, String username, String password) {
        HikariConfig config = new HikariConfig();
        config.setJdbcUrl(jdbcUrl);
        config.setUsername(username);
        config.setPassword(password);
        config.setMaximumPoolSize(5);
        config.setMinimumIdle(1);
        config.setConnectionTimeout(10_000);
        config.setIdleTimeout(300_000);
        config.setMaxLifetime(600_000);
        config.setPoolName("SusuMySQL");

        dataSource = new HikariDataSource(config);
        LOGGER.info("MySQLProvider pool created.");
    }

    @Override
    public Connection getConnection() throws SQLException {
        return dataSource.getConnection();
    }

    @Override
    public void initialize() throws SQLException {
        new MigrationRunner(this).run();
    }

    @Override
    public void close() {
        if (dataSource != null && !dataSource.isClosed()) {
            dataSource.close();
            LOGGER.info("MySQLProvider pool closed.");
        }
    }

    @Override
    public String getType() {
        return "mysql";
    }
}
