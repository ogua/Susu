package service;

import db.DatabaseConnection;
import enums.LedgerAccountType;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.LocalDate;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.List;
import models.Customer;
import models.DefaulterRow;
import models.LedgerAccount;

/**
 * Back-office reports (Trial Balance, Defaulters, Cash Position) — the
 * desktop-standalone mirror of the backend's equivalent Filament pages, so a
 * standalone company gets the same oversight views a hybrid/cloud company
 * gets in the web admin.
 */
public class ReportService {

    private final CustomerService customerService = new CustomerService();

    /**
     * Every ledger account, split by normal-balance side (mirrors the
     * backend's Trial Balance page) — this install represents one company,
     * so unlike the backend there's no branch/company scoping to apply.
     */
    public List<LedgerAccount> trialBalance() throws SQLException {
        List<LedgerAccount> accounts = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM ledger_accounts ORDER BY type, code");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                accounts.add(mapAccount(rs));
            }
        }
        return accounts;
    }

    /** The branch cash account (CASH-MAIN) plus every agent's cash-in-hand account. */
    public List<LedgerAccount> cashPosition() throws SQLException {
        List<LedgerAccount> accounts = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM ledger_accounts WHERE code = 'CASH-MAIN' OR accountable_type = 'user'"
                     + " ORDER BY code");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                accounts.add(mapAccount(rs));
            }
        }
        return accounts;
    }

    /** Every overdue loan installment, oldest-first — the chase list. */
    public List<DefaulterRow> defaulters() throws SQLException {
        List<Object[]> raw = new ArrayList<>(); // [loanNumber, customerId, agentId, dueDate, remaining]
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT l.loan_number, l.customer_id, l.agent_id, li.due_date,"
                     + " (li.principal_due + li.interest_due + li.penalty_due"
                     + "  - li.principal_paid - li.interest_paid - li.penalty_paid) AS remaining"
                     + " FROM loan_installments li JOIN loans l ON l.id = li.loan_id"
                     + " WHERE li.status = 'overdue' ORDER BY li.due_date");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                raw.add(new Object[]{
                    rs.getString("loan_number"),
                    rs.getString("customer_id"),
                    rs.getString("agent_id"),
                    rs.getString("due_date"),
                    Math.max(0, rs.getLong("remaining")),
                });
            }
        }

        List<DefaulterRow> rows = new ArrayList<>();
        LocalDate today = LocalDate.now();
        for (Object[] r : raw) {
            Customer customer = customerService.findById((String) r[1]);
            String agentName = agentName((String) r[2]);
            long daysOverdue = ChronoUnit.DAYS.between(LocalDate.parse((String) r[3]), today);

            rows.add(new DefaulterRow(
                    (String) r[0],
                    customer != null ? customer.fullName() : "—",
                    customer != null ? customer.getPhone() : "—",
                    agentName,
                    daysOverdue,
                    (long) r[4]
            ));
        }
        return rows;
    }

    private String agentName(String agentId) throws SQLException {
        if (agentId == null) {
            return "—";
        }
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT name FROM local_users WHERE id = ?")) {
            ps.setString(1, agentId);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? rs.getString("name") : "—";
            }
        }
    }

    private LedgerAccount mapAccount(ResultSet rs) throws SQLException {
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
}
