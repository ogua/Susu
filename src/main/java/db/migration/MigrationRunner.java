package db.migration;

import db.AppConfig;
import db.provider.DatabaseProvider;
import java.io.BufferedReader;
import java.io.File;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.StandardCopyOption;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Statement;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collections;
import java.util.Comparator;
import java.util.Date;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.logging.Level;
import java.util.logging.Logger;

/**
 * Applies numbered SQL migration files from the classpath.
 *
 * Files must be named V###__description.sql (e.g. V001__initial_schema.sql)
 * and listed in src/main/resources/migrations/manifest.txt (one filename per
 * line). Add new migration files to manifest.txt — no code changes needed.
 *
 * Migrations are idempotent: already-applied versions are skipped.
 * Each file is executed in a single transaction; on error the transaction
 * is rolled back and the runner throws, halting startup.
 */
public class MigrationRunner {

    private static final Logger LOGGER = Logger.getLogger(MigrationRunner.class.getName());
    private static final String MIGRATIONS_PATH = "/migrations/";
    private static final int MAX_PRE_UPDATE_SNAPSHOTS = 10;

    private final DatabaseProvider provider;
    /** Populated by discoverMigrationFiles(); used by findMigration(). */
    private final Map<String, String> versionToFilename = new LinkedHashMap<>();

    public MigrationRunner(DatabaseProvider provider) {
        this.provider = provider;
    }

    public void run() throws SQLException {
        try (Connection conn = provider.getConnection()) {
            ensureMigrationsTable(conn);
            List<String> pending = pendingMigrations(conn);
            if (pending.isEmpty()) {
                LOGGER.info("Database schema is up to date.");
                return;
            }
            if ("sqlite".equalsIgnoreCase(provider.getType())) {
                snapshotBeforeMigration(conn, pending.get(0));
            } else if ("mysql".equalsIgnoreCase(provider.getType())) {
                snapshotMySQLBeforeMigration(pending.get(0));
            }
            for (String version : pending) {
                applyMigration(conn, version);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Pre-migration snapshots
    // -------------------------------------------------------------------------

    /**
     * Best-effort safety net: copies the SQLite database file to backups/pre-update/
     * before applying any pending migrations, so a bad patch can be undone by restoring
     * this file. Never blocks or fails startup — a snapshot failure is logged and
     * migrations proceed regardless (they still roll back transactionally on error).
     */
    private void snapshotBeforeMigration(Connection conn, String firstPendingVersion) {
        try {
            try (Statement stmt = conn.createStatement()) {
                stmt.execute("PRAGMA wal_checkpoint(FULL)");
            }

            File dbFile = new File(AppConfig.getAppDir(), AppConfig.SQLITE_DB_FILE_NAME);
            if (!dbFile.exists()) {
                return;
            }

            File backupDir = new File(new File(AppConfig.getAppDir(), "backups"), "pre-update");
            if (!backupDir.exists() && !backupDir.mkdirs()) {
                LOGGER.log(Level.WARNING, "Could not create pre-update backup directory: {0}", backupDir);
                return;
            }

            String timestamp = new SimpleDateFormat("yyyyMMdd-HHmmss").format(new Date());
            File snapshot = new File(backupDir, "susuapp-" + firstPendingVersion + "-" + timestamp + ".db");
            Files.copy(dbFile.toPath(), snapshot.toPath(), StandardCopyOption.REPLACE_EXISTING);
            LOGGER.log(Level.INFO, "Pre-update snapshot saved to {0}", snapshot);

            pruneOldSnapshots(backupDir);
        } catch (Exception e) {
            LOGGER.log(Level.WARNING, "Could not create pre-update snapshot (continuing without it): {0}", e.getMessage());
        }
    }

    /**
     * Best-effort mysqldump before applying migrations on MySQL, where DDL is
     * NOT transactional so the per-file rollback contract does not hold.
     * Skips silently if mysqldump is not on PATH; never blocks startup.
     */
    private void snapshotMySQLBeforeMigration(String firstPendingVersion) {
        try {
            String url = AppConfig.get("db.url", "");
            // jdbc:mysql://host:port/dbname?params
            java.util.regex.Matcher m = java.util.regex.Pattern
                    .compile("jdbc:mysql://([^:/]+)(?::(\\d+))?/([^?]+)").matcher(url);
            if (!m.find()) {
                LOGGER.log(Level.WARNING, "Could not parse MySQL URL for pre-update dump: {0}", url);
                return;
            }
            String host = m.group(1);
            String port = m.group(2) != null ? m.group(2) : "3306";
            String database = m.group(3);

            File backupDir = new File(new File(AppConfig.getAppDir(), "backups"), "pre-update");
            if (!backupDir.exists() && !backupDir.mkdirs()) {
                LOGGER.log(Level.WARNING, "Could not create pre-update backup directory: {0}", backupDir);
                return;
            }
            String timestamp = new SimpleDateFormat("yyyyMMdd-HHmmss").format(new Date());
            File dump = new File(backupDir, "susuapp-" + firstPendingVersion + "-" + timestamp + ".sql");

            ProcessBuilder pb = new ProcessBuilder("mysqldump",
                    "--host=" + host, "--port=" + port,
                    "--user=" + AppConfig.get("db.username", "root"),
                    "--single-transaction", "--skip-lock-tables", database);
            pb.environment().put("MYSQL_PWD", AppConfig.get("db.password", ""));
            pb.redirectOutput(dump);
            pb.redirectErrorStream(false);
            Process p = pb.start();
            if (!p.waitFor(120, java.util.concurrent.TimeUnit.SECONDS)) {
                p.destroyForcibly();
                LOGGER.warning("mysqldump timed out — continuing without pre-update dump.");
                return;
            }
            if (p.exitValue() == 0 && dump.length() > 0) {
                LOGGER.log(Level.INFO, "Pre-update MySQL dump saved to {0}", dump);
                pruneOldSnapshots(backupDir);
            } else {
                dump.delete();
                LOGGER.warning("mysqldump failed (exit " + p.exitValue() + ") — continuing without pre-update dump.");
            }
        } catch (Exception e) {
            // mysqldump not installed / not on PATH lands here — deliberately non-fatal
            LOGGER.log(Level.WARNING, "Could not create pre-update MySQL dump (continuing without it): {0}", e.getMessage());
        }
    }

    private void pruneOldSnapshots(File backupDir) {
        File[] snapshots = backupDir.listFiles((dir, name) -> name.startsWith("susuapp-")
                && (name.endsWith(".db") || name.endsWith(".sql")));
        if (snapshots == null || snapshots.length <= MAX_PRE_UPDATE_SNAPSHOTS) {
            return;
        }
        Arrays.sort(snapshots, Comparator.comparing(File::getName));
        int toDelete = snapshots.length - MAX_PRE_UPDATE_SNAPSHOTS;
        for (int i = 0; i < toDelete; i++) {
            if (!snapshots[i].delete()) {
                LOGGER.log(Level.WARNING, "Could not delete old pre-update snapshot: {0}", snapshots[i]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Migration bookkeeping
    // -------------------------------------------------------------------------

    private void ensureMigrationsTable(Connection conn) throws SQLException {
        boolean isMySQL = "mysql".equalsIgnoreCase(provider.getType());
        // MySQL does not allow TEXT as a primary key; VARCHAR(20) covers all Vxxx versions.
        // SQLite accepts TEXT PRIMARY KEY without length restriction.
        String versionType = isMySQL ? "VARCHAR(20)" : "TEXT";
        String appliedAt = isMySQL
                ? "applied_at DATETIME DEFAULT CURRENT_TIMESTAMP"
                : "applied_at TEXT DEFAULT (datetime('now'))";
        try (Statement stmt = conn.createStatement()) {
            stmt.execute(
                    "CREATE TABLE IF NOT EXISTS schema_migrations ("
                    + "  version " + versionType + " PRIMARY KEY,"
                    + "  " + appliedAt
                    + ")");
        }
    }

    private List<String> pendingMigrations(Connection conn) throws SQLException {
        List<String> all = discoverMigrationFiles();
        List<String> applied = loadAppliedVersions(conn);
        List<String> pending = new ArrayList<>();
        for (String v : all) {
            if (!applied.contains(v)) {
                pending.add(v);
            }
        }
        return pending;
    }

    /**
     * Reads /migrations/manifest.txt from the classpath.
     * Each non-blank, non-comment line names one migration file.
     * Populates {@code versionToFilename} and returns sorted version strings.
     */
    private List<String> discoverMigrationFiles() throws SQLException {
        versionToFilename.clear();
        URL manifestUrl = getClass().getResource(MIGRATIONS_PATH + "manifest.txt");
        if (manifestUrl == null) {
            throw new SQLException(
                    "Migration manifest not found at classpath:" + MIGRATIONS_PATH + "manifest.txt");
        }
        try (InputStream is = manifestUrl.openStream();
             BufferedReader br = new BufferedReader(
                     new InputStreamReader(is, StandardCharsets.UTF_8))) {
            String line;
            while ((line = br.readLine()) != null) {
                line = line.strip();
                if (line.isEmpty() || line.startsWith("#")) continue;
                String version = extractVersion(line);
                versionToFilename.put(version, line);
            }
        } catch (Exception e) {
            throw new SQLException("Could not read migration manifest: " + e.getMessage(), e);
        }
        List<String> versions = new ArrayList<>(versionToFilename.keySet());
        Collections.sort(versions);
        return versions;
    }

    private URL findMigration(String version) {
        String filename = versionToFilename.get(version);
        if (filename == null) return null;
        return getClass().getResource(MIGRATIONS_PATH + filename);
    }

    private String extractVersion(String path) {
        String filename = path.substring(path.lastIndexOf('/') + 1);
        int end = filename.indexOf("__");
        return end > 0 ? filename.substring(0, end) : filename.replace(".sql", "");
    }

    private List<String> loadAppliedVersions(Connection conn) throws SQLException {
        List<String> applied = new ArrayList<>();
        try (Statement stmt = conn.createStatement();
             ResultSet rs = stmt.executeQuery(
                     "SELECT version FROM schema_migrations ORDER BY version")) {
            while (rs.next()) {
                applied.add(rs.getString("version"));
            }
        }
        return applied;
    }

    private void applyMigration(Connection conn, String version) throws SQLException {
        URL url = findMigration(version);
        if (url == null) {
            LOGGER.log(Level.WARNING,
                    "Migration file not found for version {0}, skipping.", version);
            return;
        }

        String sql = readResource(url);
        if ("mysql".equalsIgnoreCase(provider.getType())) {
            sql = adaptSqlForMysql(sql);
        }
        LOGGER.log(Level.INFO, "Applying migration {0}...", version);

        boolean autoCommit = conn.getAutoCommit();
        conn.setAutoCommit(false);
        try {
            executeSqlScript(conn, sql);
            recordMigration(conn, version);
            conn.commit();
            LOGGER.log(Level.INFO, "Migration {0} applied successfully.", version);
        } catch (Exception e) {
            conn.rollback();
            throw new SQLException(
                    "Migration " + version + " failed and was rolled back: " + e.getMessage(), e);
        } finally {
            conn.setAutoCommit(autoCommit);
        }
    }

    private void executeSqlScript(Connection conn, String script) throws SQLException {
        // Drop full-line comments before splitting: a semicolon inside a comment
        // would otherwise split it so its tail merges into the next fragment and
        // gets executed as a junk statement.
        StringBuilder withoutComments = new StringBuilder();
        for (String line : script.split("\n", -1)) {
            if (!line.strip().startsWith("--")) {
                withoutComments.append(line).append('\n');
            }
        }
        String[] statements = withoutComments.toString().split(";");
        for (String raw : statements) {
            String stmt = raw.strip();
            if (stmt.isEmpty()) continue;
            if (isCommentOnly(stmt)) continue;
            // Skip transaction control — applyMigration() manages its own transaction
            String upper = stmt.toUpperCase();
            if (upper.startsWith("BEGIN") || upper.equals("COMMIT")
                    || upper.startsWith("ROLLBACK")) continue;
            try (Statement s = conn.createStatement()) {
                s.execute(stmt);
            }
        }
    }

    private boolean isCommentOnly(String fragment) {
        for (String line : fragment.split("\n")) {
            String trimmed = line.strip();
            if (!trimmed.isEmpty() && !trimmed.startsWith("--")) {
                return false;
            }
        }
        return true;
    }

    private void recordMigration(Connection conn, String version) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement(
                "INSERT INTO schema_migrations (version) VALUES (?)")) {
            ps.setString(1, version);
            ps.executeUpdate();
        }
    }

    /**
     * Rewrites SQLite-dialect keywords to their MySQL/MariaDB equivalents so a
     * single migration file set serves both engines.
     */
    private String adaptSqlForMysql(String sql) {
        // Case-insensitive replacement of AUTOINCREMENT -> AUTO_INCREMENT.
        sql = sql.replaceAll("(?i)\\bAUTOINCREMENT\\b", "AUTO_INCREMENT");
        // Oracle MySQL rejects IF NOT EXISTS on CREATE INDEX (MariaDB/SQLite accept it).
        // Safe to strip: each migration runs at most once per database.
        sql = sql.replaceAll("(?i)CREATE\\s+INDEX\\s+IF\\s+NOT\\s+EXISTS", "CREATE INDEX");
        // Lines prefixed "--mysql:" hold MySQL-only statements (e.g. ALTER ... MODIFY,
        // which SQLite lacks). SQLite sees them as comments; here the prefix is
        // stripped so the statement executes.
        sql = sql.replaceAll("(?im)^--mysql:", "");
        return sql;
    }

    private String readResource(URL url) throws SQLException {
        try (InputStream is = url.openStream();
             BufferedReader reader = new BufferedReader(
                     new InputStreamReader(is, StandardCharsets.UTF_8))) {
            StringBuilder sb = new StringBuilder();
            String line;
            while ((line = reader.readLine()) != null) {
                sb.append(line).append('\n');
            }
            return sb.toString();
        } catch (Exception e) {
            throw new SQLException(
                    "Could not read migration file " + url + ": " + e.getMessage(), e);
        }
    }
}
