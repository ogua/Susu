package db.provider;

import db.AppConfig;

/**
 * Creates the correct DatabaseProvider based on the db.type config key.
 */
public class ProviderFactory {

    private ProviderFactory() {}

    public static DatabaseProvider create() {
        String type = AppConfig.get("db.type", "sqlite").toLowerCase();
        return switch (type) {
            case "sqlite" -> new SQLiteProvider();
            case "mysql", "mariadb" -> new MySQLProvider();
            default -> throw new IllegalStateException(
                    "Unknown db.type '" + type + "' in config.properties. Expected: sqlite, mysql, mariadb");
        };
    }
}
