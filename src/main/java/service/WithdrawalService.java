package service;

import db.DatabaseConnection;
import enums.AccountStatus;
import enums.PaymentMethod;
import enums.TransactionType;
import enums.WithdrawalStatus;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import models.JournalEntry;
import models.SavingsAccount;
import models.WithdrawalRequest;

/** Withdrawal lifecycle: request → approve/reject → pay. Mirrors App\Actions\Savings\*WithdrawalAction. */
public class WithdrawalService {

    private final LedgerService ledger = new LedgerService();
    private final ChartOfAccounts chart = new ChartOfAccounts();
    private final SavingsAccountService accountService = new SavingsAccountService();

    public WithdrawalRequest request(String accountId, String customerId, long amount, String reason,
                                      String requestedBy) throws SQLException {
        SavingsAccount account = accountService.findById(accountId);
        if (account == null || account.getStatus() != AccountStatus.ACTIVE) {
            throw new IllegalArgumentException("Withdrawals are only possible on active accounts.");
        }

        long held = heldAmount(accountId);
        if (amount <= 0 || amount > account.getBalance() - held) {
            throw new IllegalArgumentException("Requested amount exceeds the available balance.");
        }

        try (Connection conn = DatabaseConnection.getConnection()) {
            String id = UUID.randomUUID().toString();
            String now = Instant.now().toString();
            String sql = "INSERT INTO withdrawal_requests (id, savings_account_id, customer_id, amount, reason,"
                    + " status, requested_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, accountId);
                ps.setString(3, customerId);
                ps.setLong(4, amount);
                ps.setString(5, reason);
                ps.setString(6, WithdrawalStatus.PENDING.value());
                ps.setString(7, requestedBy);
                ps.setString(8, now);
                ps.setString(9, now);
                ps.executeUpdate();
            }
            return findById(conn, id);
        }
    }

    public WithdrawalRequest approve(String requestId, String approvedBy) throws SQLException {
        assertStatus(requestId, WithdrawalStatus.PENDING, "approved");
        updateStatus(requestId, WithdrawalStatus.APPROVED, approvedBy, null);
        return findById(requestId);
    }

    public WithdrawalRequest reject(String requestId, String rejectedBy, String reason) throws SQLException {
        assertStatus(requestId, WithdrawalStatus.PENDING, "rejected");
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE withdrawal_requests SET status = ?, approved_by = ?, rejected_reason = ?,"
                     + " updated_at = ? WHERE id = ?")) {
            ps.setString(1, WithdrawalStatus.REJECTED.value());
            ps.setString(2, rejectedBy);
            ps.setString(3, reason);
            ps.setString(4, Instant.now().toString());
            ps.setString(5, requestId);
            ps.executeUpdate();
        }
        return findById(requestId);
    }

    public WithdrawalRequest pay(String requestId, String paidBy) throws SQLException {
        WithdrawalRequest request = assertStatus(requestId, WithdrawalStatus.APPROVED, "paid");
        SavingsAccount account = accountService.findById(request.getSavingsAccountId());

        if (request.getAmount() > account.getBalance()) {
            throw new IllegalArgumentException("Account balance is insufficient.");
        }

        long balanceAfter = account.getBalance() - request.getAmount();

        JournalEntry entry = ledger.post(EntryRequest.of(TransactionType.WITHDRAWAL, List.of(
                LedgerLine.debit(account.getLedgerAccountId(), request.getAmount()),
                LedgerLine.credit(chart.branchCash().getId(), request.getAmount())
        )).paymentMethod(PaymentMethod.CASH)
                .recordedBy(paidBy)
                .description("Withdrawal " + account.getAccountNumber()));

        try (Connection conn = DatabaseConnection.getConnection()) {
            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE savings_accounts SET balance = ?, updated_at = ? WHERE id = ?")) {
                ps.setLong(1, balanceAfter);
                ps.setString(2, Instant.now().toString());
                ps.setString(3, account.getId());
                ps.executeUpdate();
            }
            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE withdrawal_requests SET status = ?, paid_entry_id = ?, updated_at = ? WHERE id = ?")) {
                ps.setString(1, WithdrawalStatus.PAID.value());
                ps.setString(2, entry.getId());
                ps.setString(3, Instant.now().toString());
                ps.setString(4, requestId);
                ps.executeUpdate();
            }
        }

        return findById(requestId);
    }

    public List<WithdrawalRequest> findPending() throws SQLException {
        List<WithdrawalRequest> requests = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM withdrawal_requests WHERE status IN ('pending','approved') ORDER BY created_at");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                requests.add(map(rs));
            }
        }
        return requests;
    }

    private long heldAmount(String accountId) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT COALESCE(SUM(amount),0) FROM withdrawal_requests"
                     + " WHERE savings_account_id = ? AND status IN ('pending','approved')")) {
            ps.setString(1, accountId);
            try (ResultSet rs = ps.executeQuery()) {
                rs.next();
                return rs.getLong(1);
            }
        }
    }

    private WithdrawalRequest assertStatus(String requestId, WithdrawalStatus expected, String action)
            throws SQLException {
        WithdrawalRequest request = findById(requestId);
        if (request == null || request.getStatus() != expected) {
            throw new IllegalStateException("Only " + expected.value() + " requests can be " + action + ".");
        }
        return request;
    }

    private void updateStatus(String requestId, WithdrawalStatus status, String approvedBy, String rejectedReason)
            throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE withdrawal_requests SET status = ?, approved_by = ?, rejected_reason = ?,"
                     + " updated_at = ? WHERE id = ?")) {
            ps.setString(1, status.value());
            ps.setString(2, approvedBy);
            ps.setString(3, rejectedReason);
            ps.setString(4, Instant.now().toString());
            ps.setString(5, requestId);
            ps.executeUpdate();
        }
    }

    public WithdrawalRequest findById(String id) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection()) {
            return findById(conn, id);
        }
    }

    private WithdrawalRequest findById(Connection conn, String id) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement("SELECT * FROM withdrawal_requests WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? map(rs) : null;
            }
        }
    }

    private WithdrawalRequest map(ResultSet rs) throws SQLException {
        WithdrawalRequest request = new WithdrawalRequest();
        request.setId(rs.getString("id"));
        request.setSavingsAccountId(rs.getString("savings_account_id"));
        request.setCustomerId(rs.getString("customer_id"));
        request.setAmount(rs.getLong("amount"));
        request.setReason(rs.getString("reason"));
        request.setStatus(WithdrawalStatus.fromValue(rs.getString("status")));
        request.setRequestedBy(rs.getString("requested_by"));
        request.setApprovedBy(rs.getString("approved_by"));
        request.setRejectedReason(rs.getString("rejected_reason"));
        request.setPaidEntryId(rs.getString("paid_entry_id"));
        return request;
    }
}
