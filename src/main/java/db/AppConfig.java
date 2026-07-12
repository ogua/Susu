package db;

import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.IOException;
import java.nio.file.FileSystems;
import java.nio.file.Files;
import java.nio.file.attribute.PosixFilePermissions;
import java.util.Properties;
import java.util.logging.Level;
import java.util.logging.Logger;

/**
 * Reads and writes application configuration from ~/.susudesktop/config.properties.
 * Stored outside the classpath so settings survive application upgrades.
 *
 * Key settings:
 *   db.type       sqlite | mysql | mariadb   (storage profile)
 *   sync.enabled  true | false               (hybrid vs standalone mode)
 *   api.base_url  cloud/on-prem SusuApp Laravel server (hybrid mode only)
 */
public class AppConfig {

    private static final Logger LOGGER = Logger.getLogger(AppConfig.class.getName());
    private static final String APP_DIR_NAME = ".susudesktop";
    private static final String CONFIG_FILE_NAME = "config.properties";
    public static final String SQLITE_DB_FILE_NAME = "susuapp.db";

    private static final Properties props = new Properties();
    private static File configFile;
    private static boolean loaded = false;

    static {
        load();
    }

    private AppConfig() {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public static String get(String key) {
        ensureLoaded();
        return props.getProperty(key);
    }

    public static String get(String key, String defaultValue) {
        ensureLoaded();
        return props.getProperty(key, defaultValue);
    }

    public static boolean getBoolean(String key, boolean defaultValue) {
        String val = get(key);
        if (val == null) return defaultValue;
        return Boolean.parseBoolean(val.trim());
    }

    public static void set(String key, String value) {
        ensureLoaded();
        props.setProperty(key, value);
        save();
    }

    public static boolean isSetupComplete() {
        return getBoolean("app.setup_complete", false);
    }

    public static void markSetupComplete() {
        set("app.setup_complete", "true");
    }

    /** Hybrid mode = local DB plus background sync with the Laravel backend. */
    public static boolean isSyncEnabled() {
        return getBoolean("sync.enabled", false);
    }

    public static String getApiBaseUrl() {
        return get("api.base_url", "http://localhost:8000");
    }

    /** Returns the app data directory, creating it if necessary. */
    public static File getAppDir() {
        File dir = new File(System.getProperty("user.home"), APP_DIR_NAME);
        if (!dir.exists()) {
            dir.mkdirs();
        }
        return dir;
    }

    // -------------------------------------------------------------------------
    // Storage profile defaults
    // -------------------------------------------------------------------------

    public static void applyMySQLDefaults(String host, String port, String database,
                                          String username, String password) {
        props.setProperty("db.type", "mysql");
        props.setProperty("db.url", "jdbc:mysql://" + host + ":" + port + "/" + database
                + "?useSSL=false&allowPublicKeyRetrieval=true&serverTimezone=UTC");
        props.setProperty("db.username", username);
        props.setProperty("db.password", password);
        save();
    }

    public static void applySQLiteDefaults() {
        props.setProperty("db.type", "sqlite");
        File dbFile = new File(getAppDir(), SQLITE_DB_FILE_NAME);
        props.setProperty("db.url", "jdbc:sqlite:" + dbFile.getAbsolutePath());
        props.setProperty("db.username", "");
        props.setProperty("db.password", "");
        save();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static void ensureLoaded() {
        if (!loaded) {
            load();
        }
    }

    private static void load() {
        configFile = new File(getAppDir(), CONFIG_FILE_NAME);
        if (configFile.exists()) {
            try (FileInputStream fis = new FileInputStream(configFile)) {
                props.load(fis);
                loaded = true;
            } catch (IOException e) {
                LOGGER.log(Level.WARNING, "Could not load config.properties: {0}", e.getMessage());
            }
        } else {
            // Fresh install: default to the embedded engine until the setup
            // wizard runs (app.setup_complete stays false).
            applySQLiteDefaults();
            loaded = true;
        }
    }

    private static void save() {
        if (configFile == null) {
            configFile = new File(getAppDir(), CONFIG_FILE_NAME);
        }
        try (FileOutputStream fos = new FileOutputStream(configFile)) {
            props.store(fos, "SusuApp Desktop configuration — do not edit while the application is running");
            restrictFilePermissions(configFile);
        } catch (IOException e) {
            LOGGER.log(Level.SEVERE, "Could not save config.properties: {0}", e.getMessage());
        }
    }

    /**
     * Restricts the config file to owner read/write only (600) where the
     * filesystem supports it; Windows relies on user-home ACL inheritance.
     */
    private static void restrictFilePermissions(File file) {
        try {
            if (FileSystems.getDefault().supportedFileAttributeViews().contains("posix")) {
                Files.setPosixFilePermissions(
                        file.toPath(),
                        PosixFilePermissions.fromString("rw-------"));
            } else {
                file.setReadable(false, false);
                file.setReadable(true, true);
                file.setWritable(false, false);
                file.setWritable(true, true);
                file.setExecutable(false, false);
            }
        } catch (Exception e) {
            LOGGER.log(Level.WARNING, "Could not restrict config file permissions: {0}", e.getMessage());
        }
    }
}
