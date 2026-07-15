package service;

import db.DatabaseConnection;
import db.provider.DatabaseProvider;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.ResultSetMetaData;
import java.sql.SQLException;
import java.sql.Statement;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

/**
 * Copies every domain table from the currently-active engine into a
 * freshly-migrated target engine (G5: SQLite ⇄ MySQL) — the standalone
 * mirror of Oguaschoolz's MigrationExportService. Neither engine enforces
 * foreign-key constraints at the schema level (plain TEXT id columns, no
 * REFERENCES clauses), so tables can be copied in any order; the order below
 * is parents-first purely for readability.
 *
 * <p>The source database is never modified or deleted — after a successful
 * migration it's simply left behind as an implicit backup at its original
 * location/credentials.</p>
 */
public class EngineSwitchService {

    /** app_settings/sync_state/outbox are per-machine runtime state, not domain data — never copied. */
    private static final List<String> TABLES = List.of(
            "local_users", "license",
            "customers", "savings_products", "loan_products", "ledger_accounts",
            "savings_accounts", "journal_entries", "journal_lines",
            "withdrawal_requests", "agent_daily_summaries",
            "loans", "loan_installments",
            "groups_table", "group_members", "group_rounds", "group_contributions"
    );

    public record TableResult(String table, int sourceRows, int targetRows) {
        public boolean matches() {
            return sourceRows == targetRows;
        }
    }

    /**
     * Copies every table into {@code target} (already migrated, expected
     * empty — call {@link DatabaseProvider#hasExistingData()} first and warn
     * the caller before invoking this if it's not). Does not touch
     * AppConfig/DatabaseConnection; the caller decides when to switch over
     * (typically only after inspecting the returned per-table counts).
     */
    public List<TableResult> migrate(DatabaseProvider target) throws SQLException {
        DatabaseProvider source = DatabaseConnection.getActiveProvider();
        List<TableResult> results = new ArrayList<>();

        try (Connection sourceConn = source.getConnection();
             Connection targetConn = target.getConnection()) {
            for (String table : TABLES) {
                int copied = copyTable(sourceConn, targetConn, table);
                int targetCount = countRows(targetConn, table);
                results.add(new TableResult(table, copied, targetCount));
            }
        }
        return results;
    }

    private int copyTable(Connection source, Connection target, String table) throws SQLException {
        try (Statement selectStmt = source.createStatement();
             ResultSet rs = selectStmt.executeQuery("SELECT * FROM " + table)) {
            ResultSetMetaData meta = rs.getMetaData();
            int columnCount = meta.getColumnCount();
            List<String> columns = new ArrayList<>();
            for (int i = 1; i <= columnCount; i++) {
                columns.add(meta.getColumnName(i));
            }

            String insertSql = "INSERT INTO " + table + " (" + String.join(",", columns) + ") VALUES ("
                    + String.join(",", Collections.nCopies(columnCount, "?")) + ")";

            int copied = 0;
            try (PreparedStatement insert = target.prepareStatement(insertSql)) {
                while (rs.next()) {
                    for (int i = 1; i <= columnCount; i++) {
                        insert.setObject(i, rs.getObject(i));
                    }
                    insert.addBatch();
                    copied++;
                    if (copied % 500 == 0) {
                        insert.executeBatch();
                    }
                }
                insert.executeBatch();
            }
            return copied;
        }
    }

    private int countRows(Connection conn, String table) throws SQLException {
        try (Statement stmt = conn.createStatement();
             ResultSet rs = stmt.executeQuery("SELECT COUNT(*) FROM " + table)) {
            rs.next();
            return rs.getInt(1);
        }
    }
}
