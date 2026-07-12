package service;

import db.DatabaseConnection;
import enums.LedgerAccountType;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.UUID;
import models.LedgerAccount;

/**
 * Resolves (creating on first use) the system and entity sub-accounts every
 * posting pattern needs. Mirrors the backend's
 * {@code App\Services\Ledger\ChartOfAccounts}. Codes are unique for this
 * install's chart.
 */
public class ChartOfAccounts {

    public LedgerAccount branchCash() throws SQLException {
        return firstOrCreate("CASH-MAIN", "Branch Cash", LedgerAccountType.ASSET, null, null, true);
    }

    public LedgerAccount commissionIncome() throws SQLException {
        return firstOrCreate("4100-COMM", "Susu Commission Income", LedgerAccountType.INCOME, null, null, true);
    }

    /** The agent's cash-in-hand: expected cash for reconciliation is its balance. */
    public LedgerAccount agentCash(String agentId, String agentName) throws SQLException {
        return firstOrCreate("AGT-" + shortId(agentId), agentName + " Cash In Hand",
                LedgerAccountType.ASSET, "user", agentId, true);
    }

    /** The customer's savings balance is the company's liability to them. */
    public LedgerAccount savingsLiability(String savingsAccountId, String accountNumber) throws SQLException {
        return firstOrCreate("SAV-" + accountNumber, "Savings " + accountNumber,
                LedgerAccountType.LIABILITY, "savings_account", savingsAccountId, true);
    }

    private LedgerAccount firstOrCreate(String code, String name, LedgerAccountType type,
                                         String accountableType, String accountableId, boolean system)
            throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection()) {
            try (PreparedStatement ps = conn.prepareStatement("SELECT * FROM ledger_accounts WHERE code = ?")) {
                ps.setString(1, code);
                try (ResultSet rs = ps.executeQuery()) {
                    if (rs.next()) {
                        return map(rs);
                    }
                }
            }

            String id = UUID.randomUUID().toString();
            String now = Instant.now().toString();
            String sql = "INSERT INTO ledger_accounts (id, code, name, type, accountable_type, accountable_id,"
                    + " balance, is_system, created_at, updated_at) VALUES (?,?,?,?,?,?,0,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, code);
                ps.setString(3, name);
                ps.setString(4, type.value());
                ps.setString(5, accountableType);
                ps.setString(6, accountableId);
                ps.setInt(7, system ? 1 : 0);
                ps.setString(8, now);
                ps.setString(9, now);
                ps.executeUpdate();
            }

            LedgerAccount account = new LedgerAccount();
            account.setId(id);
            account.setCode(code);
            account.setName(name);
            account.setType(type);
            account.setAccountableType(accountableType);
            account.setAccountableId(accountableId);
            account.setBalance(0);
            account.setSystem(system);
            return account;
        }
    }

    private LedgerAccount map(ResultSet rs) throws SQLException {
        LedgerAccount account = new LedgerAccount();
        account.setId(rs.getString("id"));
        account.setCode(rs.getString("code"));
        account.setName(rs.getString("name"));
        account.setType(LedgerAccountType.fromValue(rs.getString("type")));
        account.setAccountableType(rs.getString("accountable_type"));
        account.setAccountableId(rs.getString("accountable_id"));
        account.setBalance(rs.getLong("balance"));
        account.setSystem(rs.getInt("is_system") != 0);
        return account;
    }

    private String shortId(String id) {
        return id.length() > 8 ? id.substring(0, 8) : id;
    }
}
