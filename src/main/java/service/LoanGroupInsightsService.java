package service;

import db.DatabaseConnection;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * A customer group's overview, history and collection sheet ("Enter
 * Transaction") — parity ports of the backend's BuildLoanGroupSummaryAction,
 * BuildLoanGroupHistoryAction and Build/PostCollectionSheetAction. Every read
 * runs on one connection; posting calls the regular services one at a time,
 * outside any open connection (single-connection SQLite pool).
 */
public class LoanGroupInsightsService {

    public record Summary(int activeMembers, int activeLoans, int draftLoans, long totalDisbursed, long totalPaid,
                          long outstanding, long overdue, long depositsCollected) {}

    public record HistoryEvent(String at, String description, String member, Long amount) {}

    public record SheetRow(String customerId, String customerName, String phone, String groupLoanId, String loanNumber,
                           long outstanding, long amountDue, long overdue, String savingsAccountId,
                           String savingsAccountNumber, Long savingsBalance, Long contributionAmount) {}

    public record SheetEntry(SheetRow row, long repayment, long deposit) {}

    public record PostResult(int repaymentsCount, long repaymentsTotal, int depositsCount, long depositsTotal) {}

    /** Ids created, plus "member name: reason" for every member skipped. */
    public record BulkResult(List<String> created, List<String> skipped) {}

    private final GroupLoanService groupLoanService = new GroupLoanService();
    private final LoanGroupService loanGroupService = new LoanGroupService();
    private final SavingsAccountService savingsAccountService = new SavingsAccountService();
    private final SavingsProductService savingsProductService = new SavingsProductService();

    /**
     * "Apply a loan to the group": the same terms to every active member, one
     * draft loan each — parity port of IssueLoansToGroupAction. Members who
     * can't take one (e.g. an open loan) are skipped, not fatal. Each issue
     * queues its own group_loan.issue outbox op.
     */
    public BulkResult issueLoansToGroup(String agentId, String loanGroupId, long principal, long securityDeposit,
                                        long periodicAmount, enums.LoanFrequency frequency, LocalDate startDate,
                                        String notes) throws SQLException {
        List<models.LoanGroupMember> members = loanGroupService.findMembers(loanGroupId).stream()
                .filter(member -> "active".equals(member.getStatus()))
                .toList();
        if (members.isEmpty()) {
            throw new IllegalArgumentException("The group has no active members to issue loans to.");
        }

        List<String> created = new ArrayList<>();
        List<String> skipped = new ArrayList<>();
        for (models.LoanGroupMember member : members) {
            try {
                created.add(groupLoanService.issue(agentId, loanGroupId, member.getCustomerId(), principal, securityDeposit,
                        periodicAmount, frequency, startDate, notes, null).getId());
            } catch (IllegalArgumentException | IllegalStateException e) {
                skipped.add(memberName(member) + ": " + e.getMessage());
            }
        }
        return new BulkResult(created, skipped);
    }

    /**
     * "Apply a saving to the group": a daily-susu account for every active
     * member who lacks one on this product — parity port of
     * OpenSavingsForGroupAction. Each open queues its own account.open op.
     */
    public BulkResult openSavingsForGroup(String agentId, String loanGroupId, String savingsProductId,
                                          Long contributionAmount) throws SQLException {
        models.SavingsProduct product = savingsProductService.findActive().stream()
                .filter(candidate -> candidate.getId().equals(savingsProductId))
                .findFirst()
                .orElseThrow(() -> new IllegalArgumentException("This savings product is not available."));
        if (!"daily_susu".equals(product.getType())) {
            throw new IllegalArgumentException("Only daily susu products can be opened for a whole group — target and"
                    + " fixed deposits need per-member amounts and dates.");
        }

        List<String> created = new ArrayList<>();
        List<String> skipped = new ArrayList<>();
        for (models.LoanGroupMember member : loanGroupService.findMembers(loanGroupId)) {
            if (!"active".equals(member.getStatus())) {
                continue;
            }
            boolean hasOne = savingsAccountService.findByCustomer(member.getCustomerId()).stream()
                    .anyMatch(account -> savingsProductId.equals(account.getSavingsProductId())
                            && account.getStatus() == enums.AccountStatus.ACTIVE);
            if (hasOne) {
                skipped.add(memberName(member) + ": already has this account.");
                continue;
            }
            try {
                created.add(savingsAccountService.open(member.getCustomerId(), savingsProductId, agentId, contributionAmount).getId());
            } catch (IllegalArgumentException | IllegalStateException e) {
                skipped.add(memberName(member) + ": " + e.getMessage());
            }
        }
        return new BulkResult(created, skipped);
    }

    private static String memberName(models.LoanGroupMember member) {
        return member.getCustomer() != null ? member.getCustomer().fullName() : member.getCustomerId();
    }
    private final CollectionService collectionService = new CollectionService();

    public Summary summary(String loanGroupId) throws SQLException {
        String today = LocalDate.now().toString();
        try (Connection conn = DatabaseConnection.getConnection()) {
            return new Summary(
                    (int) scalar(conn, "SELECT COUNT(*) FROM loan_group_members WHERE loan_group_id = ? AND status = 'active'", loanGroupId),
                    (int) scalar(conn, "SELECT COUNT(*) FROM group_loans WHERE loan_group_id = ? AND status = 'active'", loanGroupId),
                    (int) scalar(conn, "SELECT COUNT(*) FROM group_loans WHERE loan_group_id = ? AND status = 'draft'", loanGroupId),
                    scalar(conn, "SELECT COALESCE(SUM(principal_amount), 0) FROM group_loans WHERE loan_group_id = ? AND activated_at IS NOT NULL", loanGroupId),
                    scalar(conn, "SELECT COALESCE(SUM(r.amount), 0) FROM group_loan_repayments r JOIN group_loans l ON l.id = r.group_loan_id WHERE l.loan_group_id = ?", loanGroupId),
                    scalar(conn, "SELECT COALESCE(SUM(outstanding_balance), 0) FROM group_loans WHERE loan_group_id = ? AND status = 'active'", loanGroupId),
                    scalar(conn, "SELECT COALESCE(SUM(i.amount_due - i.amount_paid), 0) FROM group_loan_installments i JOIN group_loans l ON l.id = i.group_loan_id"
                            + " WHERE l.loan_group_id = ? AND l.status = 'active' AND i.due_date < '" + today + "'", loanGroupId),
                    scalar(conn, "SELECT COALESCE(SUM(d.amount), 0) FROM group_loan_deposits d JOIN group_loans l ON l.id = d.group_loan_id WHERE l.loan_group_id = ?", loanGroupId));
        }
    }

    /** Newest first: membership, each loan's lifecycle, deposits and repayments. */
    public List<HistoryEvent> history(String loanGroupId) throws SQLException {
        List<HistoryEvent> events = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection()) {
            try (PreparedStatement ps = conn.prepareStatement(
                    "SELECT m.joined_at, m.left_at, c.first_name, c.last_name FROM loan_group_members m"
                    + " JOIN customers c ON c.id = m.customer_id WHERE m.loan_group_id = ?")) {
                ps.setString(1, loanGroupId);
                try (ResultSet rs = ps.executeQuery()) {
                    while (rs.next()) {
                        String name = name(rs);
                        events.add(new HistoryEvent(rs.getString("joined_at"), name + " joined the group", name, null));
                        if (rs.getString("left_at") != null) {
                            events.add(new HistoryEvent(rs.getString("left_at"), name + " left the group", name, null));
                        }
                    }
                }
            }
            try (PreparedStatement ps = conn.prepareStatement(
                    "SELECT l.loan_number, l.principal_amount, l.issued_at, l.activated_at, l.closed_at, l.cancelled_at,"
                    + " l.written_off_at, l.write_off_amount, c.first_name, c.last_name FROM group_loans l"
                    + " JOIN customers c ON c.id = l.customer_id WHERE l.loan_group_id = ?")) {
                ps.setString(1, loanGroupId);
                try (ResultSet rs = ps.executeQuery()) {
                    while (rs.next()) {
                        String name = name(rs);
                        String number = rs.getString("loan_number");
                        long principal = rs.getLong("principal_amount");
                        add(events, rs.getString("issued_at"), "Loan " + number + " issued", name, principal);
                        add(events, rs.getString("activated_at"), "Loan " + number + " disbursed", name, principal);
                        add(events, rs.getString("closed_at"), "Loan " + number + " fully repaid", name, null);
                        add(events, rs.getString("cancelled_at"), "Loan " + number + " cancelled", name, null);
                        long writeOff = rs.getLong("write_off_amount");
                        add(events, rs.getString("written_off_at"), "Loan " + number + " written off", name, rs.wasNull() ? null : writeOff);
                    }
                }
            }
            for (String[] table : new String[][] {{"group_loan_deposits", "Security deposit for "}, {"group_loan_repayments", "Repayment on "}}) {
                try (PreparedStatement ps = conn.prepareStatement(
                        "SELECT x.recorded_at, x.amount, l.loan_number, c.first_name, c.last_name FROM " + table[0] + " x"
                        + " JOIN group_loans l ON l.id = x.group_loan_id JOIN customers c ON c.id = l.customer_id"
                        + " WHERE l.loan_group_id = ?")) {
                    ps.setString(1, loanGroupId);
                    try (ResultSet rs = ps.executeQuery()) {
                        while (rs.next()) {
                            events.add(new HistoryEvent(rs.getString("recorded_at"), table[1] + rs.getString("loan_number"),
                                    name(rs), rs.getLong("amount")));
                        }
                    }
                }
            }
        }
        events.sort(Comparator.comparing(HistoryEvent::at).reversed());
        return events;
    }

    /** Every active member: their active loan in this group (if any) with what is due by {@code date}, and a savings account. */
    public List<SheetRow> sheet(String loanGroupId, LocalDate date) throws SQLException {
        Map<String, SheetRow> rows = new LinkedHashMap<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT c.id AS customer_id, c.first_name, c.last_name, c.phone,"
                     + " l.id AS loan_id, l.loan_number, l.outstanding_balance,"
                     + " (SELECT COALESCE(SUM(i.amount_due - i.amount_paid), 0) FROM group_loan_installments i"
                     + "    WHERE i.group_loan_id = l.id AND i.status <> 'paid' AND i.due_date <= ?) AS amount_due,"
                     + " (SELECT COALESCE(SUM(i.amount_due - i.amount_paid), 0) FROM group_loan_installments i"
                     + "    WHERE i.group_loan_id = l.id AND i.status <> 'paid' AND i.due_date < ?) AS overdue,"
                     + " (SELECT a.id FROM savings_accounts a JOIN savings_products p ON p.id = a.savings_product_id"
                     + "    WHERE a.customer_id = c.id AND a.status = 'active' AND p.type IN ('daily_susu', 'target')"
                     + "    ORDER BY CASE p.type WHEN 'daily_susu' THEN 0 ELSE 1 END, a.opened_at LIMIT 1) AS account_id"
                     + " FROM loan_group_members m JOIN customers c ON c.id = m.customer_id"
                     + " LEFT JOIN group_loans l ON l.loan_group_member_id = m.id AND l.status = 'active'"
                     + " WHERE m.loan_group_id = ? AND m.status = 'active'"
                     + " ORDER BY c.first_name, c.last_name")) {
            ps.setString(1, date.toString());
            ps.setString(2, date.toString());
            ps.setString(3, loanGroupId);
            List<String[]> accountLookups = new ArrayList<>();
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    String loanId = rs.getString("loan_id");
                    long outstanding = rs.getLong("outstanding_balance");
                    rows.put(rs.getString("customer_id"), new SheetRow(rs.getString("customer_id"), name(rs), rs.getString("phone"),
                            loanId, rs.getString("loan_number"), loanId == null ? 0 : outstanding,
                            loanId == null ? 0 : Math.min(rs.getLong("amount_due"), outstanding),
                            loanId == null ? 0 : Math.min(rs.getLong("overdue"), outstanding),
                            rs.getString("account_id"), null, null, null));
                    if (rs.getString("account_id") != null) {
                        accountLookups.add(new String[] {rs.getString("customer_id"), rs.getString("account_id")});
                    }
                }
            }
            try (PreparedStatement account = conn.prepareStatement(
                    "SELECT account_number, balance, contribution_amount FROM savings_accounts WHERE id = ?")) {
                for (String[] lookup : accountLookups) {
                    account.setString(1, lookup[1]);
                    try (ResultSet rs = account.executeQuery()) {
                        if (rs.next()) {
                            SheetRow row = rows.get(lookup[0]);
                            rows.put(lookup[0], new SheetRow(row.customerId(), row.customerName(), row.phone(), row.groupLoanId(),
                                    row.loanNumber(), row.outstanding(), row.amountDue(), row.overdue(), row.savingsAccountId(),
                                    rs.getString("account_number"), rs.getLong("balance"), rs.getLong("contribution_amount")));
                        }
                    }
                }
            }
        }
        return new ArrayList<>(rows.values());
    }

    /**
     * Validates every row first (so a bad row stops the sheet before anything
     * posts), then records each repayment and deposit through the normal
     * services — which also queue the usual outbox ops in hybrid mode.
     */
    public PostResult post(String agentId, String agentName, List<SheetEntry> entries) throws SQLException {
        for (int i = 0; i < entries.size(); i++) {
            SheetEntry entry = entries.get(i);
            String rowLabel = "Row " + (i + 1) + " (" + entry.row().customerName() + "): ";
            if (entry.repayment() > 0 && entry.row().groupLoanId() == null) {
                throw new IllegalArgumentException(rowLabel + "has no active loan to repay.");
            }
            if (entry.repayment() > entry.row().outstanding()) {
                throw new IllegalArgumentException(rowLabel + "repayment is more than the loan balance.");
            }
            if (entry.deposit() > 0) {
                Long contribution = entry.row().contributionAmount();
                if (entry.row().savingsAccountId() == null) {
                    throw new IllegalArgumentException(rowLabel + "has no savings account for a deposit.");
                }
                if (contribution != null && contribution > 0 && entry.deposit() % contribution != 0) {
                    throw new IllegalArgumentException(rowLabel + "deposit must be a multiple of the daily contribution.");
                }
            }
        }

        int repayments = 0;
        long repaymentsTotal = 0;
        int deposits = 0;
        long depositsTotal = 0;
        Instant now = Instant.now();
        for (SheetEntry entry : entries) {
            if (entry.repayment() > 0) {
                groupLoanService.recordRepayment(entry.row().groupLoanId(), entry.repayment(), agentId, null, now);
                repayments++;
                repaymentsTotal += entry.repayment();
            }
            if (entry.deposit() > 0) {
                collectionService.record(agentId, agentName, entry.row().savingsAccountId(), entry.deposit(), null, now);
                deposits++;
                depositsTotal += entry.deposit();
            }
        }
        return new PostResult(repayments, repaymentsTotal, deposits, depositsTotal);
    }

    private static void add(List<HistoryEvent> events, String at, String description, String member, Long amount) {
        if (at != null) {
            events.add(new HistoryEvent(at, description, member, amount));
        }
    }

    private static String name(ResultSet rs) throws SQLException {
        return ((rs.getString("first_name") == null ? "" : rs.getString("first_name")) + " "
                + (rs.getString("last_name") == null ? "" : rs.getString("last_name"))).trim();
    }

    private static long scalar(Connection conn, String sql, String param) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement(sql)) {
            ps.setString(1, param);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? rs.getLong(1) : 0;
            }
        }
    }
}
