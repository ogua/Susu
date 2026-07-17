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
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import models.AgentPerformanceRow;
import models.CollectionsRow;
import models.Customer;
import models.CustomerBalanceRow;
import models.DefaulterRow;
import models.GroupReportRow;
import models.LedgerAccount;
import models.LedgerEntryRow;
import models.LoanPortfolioRow;
import models.WithdrawalRow;

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

    /**
     * Every collection entry in the period (both dates inclusive, either may
     * be null) — mirrors the backend's Collections report.
     */
    public List<CollectionsRow> collections(LocalDate from, LocalDate to) throws SQLException {
        List<Object[]> raw = new ArrayList<>(); // [recordedAt, reference, recordedBy, description, method, status, amount]
        StringBuilder sql = new StringBuilder(
                "SELECT e.recorded_at, e.reference, e.recorded_by, e.description, e.payment_method, e.status,"
                + " (SELECT COALESCE(SUM(l.debit), 0) FROM journal_lines l WHERE l.journal_entry_id = e.id) AS amount"
                + " FROM journal_entries e"
                + " WHERE e.type = 'collection' AND e.status IN ('completed', 'reversed')");
        List<String> params = new ArrayList<>();
        if (from != null) {
            sql.append(" AND DATE(e.recorded_at) >= ?");
            params.add(from.toString());
        }
        if (to != null) {
            sql.append(" AND DATE(e.recorded_at) <= ?");
            params.add(to.toString());
        }
        sql.append(" ORDER BY e.recorded_at");

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(sql.toString())) {
            for (int i = 0; i < params.size(); i++) {
                ps.setString(i + 1, params.get(i));
            }
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    raw.add(new Object[]{
                        rs.getString("recorded_at"), rs.getString("reference"), rs.getString("recorded_by"),
                        rs.getString("description"), rs.getString("payment_method"), rs.getString("status"),
                        rs.getLong("amount"),
                    });
                }
            }
        }

        // Second pass: resolve agent names after the report connection closed
        // (single-connection pool — a nested lookup inside the block deadlocks).
        Map<String, String> names = new HashMap<>();
        List<CollectionsRow> rows = new ArrayList<>();
        for (Object[] r : raw) {
            String agentId = (String) r[2];
            String name = names.computeIfAbsent(agentId == null ? "" : agentId, key -> {
                try {
                    return agentName(key.isEmpty() ? null : key);
                } catch (SQLException e) {
                    return "—";
                }
            });
            rows.add(new CollectionsRow((String) r[0], (String) r[1], name,
                    (String) r[3], (String) r[4], (String) r[5], (long) r[6]));
        }
        return rows;
    }

    /** The whole loan book, optionally narrowed by application date and status. */
    public List<LoanPortfolioRow> loanPortfolio(LocalDate from, LocalDate to, String status) throws SQLException {
        List<Object[]> raw = new ArrayList<>(); // [loanNumber, customerId, agentId, status, appliedAt, disbursedAt, principal, outstanding]
        StringBuilder sql = new StringBuilder(
                "SELECT loan_number, customer_id, agent_id, status, applied_at, disbursed_at,"
                + " principal_amount, outstanding_balance FROM loans WHERE 1=1");
        List<String> params = new ArrayList<>();
        if (from != null) {
            sql.append(" AND DATE(applied_at) >= ?");
            params.add(from.toString());
        }
        if (to != null) {
            sql.append(" AND DATE(applied_at) <= ?");
            params.add(to.toString());
        }
        if (status != null && !status.isBlank()) {
            sql.append(" AND status = ?");
            params.add(status);
        }
        sql.append(" ORDER BY applied_at");

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(sql.toString())) {
            for (int i = 0; i < params.size(); i++) {
                ps.setString(i + 1, params.get(i));
            }
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    raw.add(new Object[]{
                        rs.getString("loan_number"), rs.getString("customer_id"), rs.getString("agent_id"),
                        rs.getString("status"), rs.getString("applied_at"), rs.getString("disbursed_at"),
                        rs.getLong("principal_amount"), rs.getLong("outstanding_balance"),
                    });
                }
            }
        }

        List<LoanPortfolioRow> rows = new ArrayList<>();
        for (Object[] r : raw) {
            Customer customer = customerService.findById((String) r[1]);
            rows.add(new LoanPortfolioRow((String) r[0],
                    customer != null ? customer.fullName() : "—",
                    agentName((String) r[2]), (String) r[3],
                    dateOnly((String) r[4]), dateOnly((String) r[5]),
                    (long) r[6], (long) r[7]));
        }
        return rows;
    }

    /** Per-agent day-sheet aggregates plus commission earned in the period. */
    public List<AgentPerformanceRow> agentPerformance(LocalDate from, LocalDate to) throws SQLException {
        // agentId -> [daysWorked, collectionsCount, collectionsTotal, varianceTotal, unreconciledDays]
        Map<String, long[]> summaries = new LinkedHashMap<>();
        StringBuilder summarySql = new StringBuilder(
                "SELECT agent_id, COUNT(*) AS days_worked, COALESCE(SUM(collections_count),0) AS cnt,"
                + " COALESCE(SUM(collections_total),0) AS total, COALESCE(SUM(variance),0) AS variance,"
                + " SUM(CASE WHEN status <> 'reconciled' THEN 1 ELSE 0 END) AS unreconciled"
                + " FROM agent_daily_summaries WHERE 1=1");
        List<String> params = new ArrayList<>();
        if (from != null) {
            summarySql.append(" AND summary_date >= ?");
            params.add(from.toString());
        }
        if (to != null) {
            summarySql.append(" AND summary_date <= ?");
            params.add(to.toString());
        }
        summarySql.append(" GROUP BY agent_id ORDER BY total DESC");

        Map<String, Long> commissions = new HashMap<>();
        try (Connection conn = DatabaseConnection.getConnection()) {
            try (PreparedStatement ps = conn.prepareStatement(summarySql.toString())) {
                for (int i = 0; i < params.size(); i++) {
                    ps.setString(i + 1, params.get(i));
                }
                try (ResultSet rs = ps.executeQuery()) {
                    while (rs.next()) {
                        summaries.put(rs.getString("agent_id"), new long[]{
                            rs.getLong("days_worked"), rs.getLong("cnt"), rs.getLong("total"),
                            rs.getLong("variance"), rs.getLong("unreconciled"),
                        });
                    }
                }
            }

            StringBuilder commissionSql = new StringBuilder(
                    "SELECT e.recorded_by, COALESCE(SUM(l.debit),0) AS commission"
                    + " FROM journal_entries e JOIN journal_lines l ON l.journal_entry_id = e.id"
                    + " WHERE e.type = 'commission' AND e.status IN ('completed', 'reversed')");
            List<String> commissionParams = new ArrayList<>();
            if (from != null) {
                commissionSql.append(" AND DATE(e.recorded_at) >= ?");
                commissionParams.add(from.toString());
            }
            if (to != null) {
                commissionSql.append(" AND DATE(e.recorded_at) <= ?");
                commissionParams.add(to.toString());
            }
            commissionSql.append(" GROUP BY e.recorded_by");

            try (PreparedStatement ps = conn.prepareStatement(commissionSql.toString())) {
                for (int i = 0; i < commissionParams.size(); i++) {
                    ps.setString(i + 1, commissionParams.get(i));
                }
                try (ResultSet rs = ps.executeQuery()) {
                    while (rs.next()) {
                        commissions.put(rs.getString("recorded_by"), rs.getLong("commission"));
                    }
                }
            }
        }

        List<AgentPerformanceRow> rows = new ArrayList<>();
        for (Map.Entry<String, long[]> entry : summaries.entrySet()) {
            long[] s = entry.getValue();
            rows.add(new AgentPerformanceRow(agentName(entry.getKey()),
                    (int) s[0], s[1], s[2], s[3], (int) s[4],
                    commissions.getOrDefault(entry.getKey(), 0L)));
        }
        return rows;
    }

    /** Every withdrawal request in the period (request date), optionally by status. */
    public List<WithdrawalRow> withdrawals(LocalDate from, LocalDate to, String status) throws SQLException {
        List<Object[]> raw = new ArrayList<>(); // [createdAt, accountNumber, customerId, status, requestedBy, approvedBy, penalty, amount]
        StringBuilder sql = new StringBuilder(
                "SELECT w.created_at, a.account_number, w.customer_id, w.status, w.requested_by,"
                + " w.approved_by, COALESCE(w.penalty_amount, 0) AS penalty, w.amount"
                + " FROM withdrawal_requests w"
                + " LEFT JOIN savings_accounts a ON a.id = w.savings_account_id WHERE 1=1");
        List<String> params = new ArrayList<>();
        if (from != null) {
            sql.append(" AND DATE(w.created_at) >= ?");
            params.add(from.toString());
        }
        if (to != null) {
            sql.append(" AND DATE(w.created_at) <= ?");
            params.add(to.toString());
        }
        if (status != null && !status.isBlank()) {
            sql.append(" AND w.status = ?");
            params.add(status);
        }
        sql.append(" ORDER BY w.created_at");

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(sql.toString())) {
            for (int i = 0; i < params.size(); i++) {
                ps.setString(i + 1, params.get(i));
            }
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    raw.add(new Object[]{
                        rs.getString("created_at"), rs.getString("account_number"), rs.getString("customer_id"),
                        rs.getString("status"), rs.getString("requested_by"), rs.getString("approved_by"),
                        rs.getLong("penalty"), rs.getLong("amount"),
                    });
                }
            }
        }

        List<WithdrawalRow> rows = new ArrayList<>();
        for (Object[] r : raw) {
            Customer customer = customerService.findById((String) r[2]);
            rows.add(new WithdrawalRow((String) r[0], (String) r[1],
                    customer != null ? customer.fullName() : "—", (String) r[3],
                    agentName((String) r[4]), agentName((String) r[5]),
                    (long) r[6], (long) r[7]));
        }
        return rows;
    }

    /** Every susu group with membership and round progress. */
    public List<GroupReportRow> groupsReport() throws SQLException {
        List<GroupReportRow> rows = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT g.name, g.code, g.status,"
                     + " (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id) AS members_count,"
                     + " (SELECT r.round_number FROM group_rounds r WHERE r.group_id = g.id"
                     + "   AND r.status <> 'completed' ORDER BY r.round_number LIMIT 1) AS current_round,"
                     + " (SELECT r.total_expected FROM group_rounds r WHERE r.group_id = g.id"
                     + "   AND r.status <> 'completed' ORDER BY r.round_number LIMIT 1) AS round_expected,"
                     + " (SELECT r.total_collected FROM group_rounds r WHERE r.group_id = g.id"
                     + "   AND r.status <> 'completed' ORDER BY r.round_number LIMIT 1) AS round_collected,"
                     + " (SELECT COUNT(*) FROM group_rounds r WHERE r.group_id = g.id AND r.paid_out_at IS NOT NULL) AS paid_out,"
                     + " (SELECT COALESCE(SUM(r.total_collected),0) FROM group_rounds r WHERE r.group_id = g.id) AS lifetime"
                     + " FROM groups_table g ORDER BY g.name");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                String currentRound = rs.getString("current_round");
                rows.add(new GroupReportRow(
                        rs.getString("name"), rs.getString("code"), rs.getString("status"),
                        rs.getInt("members_count"),
                        currentRound != null ? currentRound : "—",
                        rs.getLong("round_expected"), rs.getLong("round_collected"),
                        rs.getInt("paid_out"), rs.getLong("lifetime")));
            }
        }
        return rows;
    }

    /** As-at-now balances for every open (active or dormant) savings account. */
    public List<CustomerBalanceRow> customerBalances() throws SQLException {
        List<Object[]> raw = new ArrayList<>(); // [accountNumber, customerId, productName, agentId, status, balance]
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT a.account_number, a.customer_id, p.name AS product_name, a.agent_id, a.status, a.balance"
                     + " FROM savings_accounts a LEFT JOIN savings_products p ON p.id = a.savings_product_id"
                     + " WHERE a.status IN ('active', 'dormant') ORDER BY a.account_number");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                raw.add(new Object[]{
                    rs.getString("account_number"), rs.getString("customer_id"), rs.getString("product_name"),
                    rs.getString("agent_id"), rs.getString("status"), rs.getLong("balance"),
                });
            }
        }

        List<CustomerBalanceRow> rows = new ArrayList<>();
        for (Object[] r : raw) {
            Customer customer = customerService.findById((String) r[1]);
            rows.add(new CustomerBalanceRow((String) r[0],
                    customer != null ? customer.fullName() : "—",
                    customer != null ? customer.getPhone() : "—",
                    (String) r[2], agentName((String) r[3]), (String) r[4], (long) r[5]));
        }
        return rows;
    }

    /**
     * One account's posted journal lines with opening/running balances —
     * mirrors the backend's Account Ledger drill-down. Running balances are
     * cumulative from inception; the opening balance carries everything
     * before {@code from} into the period.
     */
    public AccountLedgerResult accountLedger(LedgerAccount account, LocalDate from, LocalDate to) throws SQLException {
        int sign = account.getType().isNormalBalanceDebit() ? 1 : -1;
        long openingBalance = 0;
        List<Object[]> raw = new ArrayList<>(); // [recordedAt, reference, type, memo/description, debit, credit]

        try (Connection conn = DatabaseConnection.getConnection()) {
            if (from != null) {
                try (PreparedStatement ps = conn.prepareStatement(
                        "SELECT COALESCE(SUM(l.debit),0) AS debits, COALESCE(SUM(l.credit),0) AS credits"
                        + " FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id"
                        + " WHERE l.ledger_account_id = ? AND e.status IN ('completed', 'reversed')"
                        + " AND DATE(e.recorded_at) < ?")) {
                    ps.setString(1, account.getId());
                    ps.setString(2, from.toString());
                    try (ResultSet rs = ps.executeQuery()) {
                        if (rs.next()) {
                            openingBalance = sign * (rs.getLong("debits") - rs.getLong("credits"));
                        }
                    }
                }
            }

            StringBuilder sql = new StringBuilder(
                    "SELECT e.recorded_at, e.reference, e.type, COALESCE(l.memo, e.description) AS description,"
                    + " l.debit, l.credit"
                    + " FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id"
                    + " WHERE l.ledger_account_id = ? AND e.status IN ('completed', 'reversed')");
            List<String> params = new ArrayList<>();
            if (from != null) {
                sql.append(" AND DATE(e.recorded_at) >= ?");
                params.add(from.toString());
            }
            if (to != null) {
                sql.append(" AND DATE(e.recorded_at) <= ?");
                params.add(to.toString());
            }
            sql.append(" ORDER BY e.recorded_at, l.id");

            try (PreparedStatement ps = conn.prepareStatement(sql.toString())) {
                ps.setString(1, account.getId());
                for (int i = 0; i < params.size(); i++) {
                    ps.setString(i + 2, params.get(i));
                }
                try (ResultSet rs = ps.executeQuery()) {
                    while (rs.next()) {
                        raw.add(new Object[]{
                            rs.getString("recorded_at"), rs.getString("reference"), rs.getString("type"),
                            rs.getString("description"), rs.getLong("debit"), rs.getLong("credit"),
                        });
                    }
                }
            }
        }

        long running = openingBalance;
        long totalDebits = 0;
        long totalCredits = 0;
        List<LedgerEntryRow> rows = new ArrayList<>();
        for (Object[] r : raw) {
            long debit = (long) r[4];
            long credit = (long) r[5];
            totalDebits += debit;
            totalCredits += credit;
            running += sign * (debit - credit);
            rows.add(new LedgerEntryRow((String) r[0], (String) r[1], (String) r[2], (String) r[3],
                    debit, credit, running));
        }

        return new AccountLedgerResult(openingBalance, running, totalDebits, totalCredits, rows);
    }

    /**
     * Branch-wide collections total per day for the last {@code days} days
     * (from day sheets, like the backend's dashboard trend). Keys are ISO
     * dates; days with no collections are absent — callers fill gaps.
     */
    public Map<String, Long> dailyCollections(int days) throws SQLException {
        Map<String, Long> totals = new LinkedHashMap<>();
        LocalDate start = LocalDate.now().minusDays(days - 1L);
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT summary_date, COALESCE(SUM(collections_total),0) AS total"
                     + " FROM agent_daily_summaries WHERE summary_date >= ?"
                     + " GROUP BY summary_date ORDER BY summary_date")) {
            ps.setString(1, start.toString());
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    totals.put(rs.getString("summary_date"), rs.getLong("total"));
                }
            }
        }
        return totals;
    }

    /** Loan counts per status — feeds the dashboard's portfolio pie chart. */
    public Map<String, Integer> loanStatusCounts() throws SQLException {
        Map<String, Integer> counts = new LinkedHashMap<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT status, COUNT(*) AS count FROM loans GROUP BY status ORDER BY status");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                counts.put(rs.getString("status"), rs.getInt("count"));
            }
        }
        return counts;
    }

    private static String dateOnly(String timestamp) {
        if (timestamp == null) {
            return "—";
        }
        return timestamp.length() >= 10 ? timestamp.substring(0, 10) : timestamp;
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
