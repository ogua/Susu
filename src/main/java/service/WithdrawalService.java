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
import models.SavingsProduct;
import models.WithdrawalRequest;

/** Withdrawal lifecycle: request → approve/reject → pay. Mirrors App\Actions\Savings\*WithdrawalAction. */
public class WithdrawalService {

    private final LedgerService ledger = new LedgerService();
    private final ChartOfAccounts chart = new ChartOfAccounts();
    private final SavingsAccountService accountService = new SavingsAccountService();
    private final SavingsProductService productService = new SavingsProductService();

    public WithdrawalRequest request(String accountId, String customerId, long amount, String reason,
                                      String requestedBy) throws SQLException {
        SavingsAccount account = accountService.findById(accountId);
        if (account == null || account.getStatus() != AccountStatus.ACTIVE) {
            throw new IllegalArgumentException("Withdrawals are only possible on active accounts.");
        }

        SavingsProduct product = productService.findById(account.getSavingsProductId());
        if (product != null && product.isFixedDeposit() && account.getMaturedAt() == null) {
            throw new IllegalStateException("Fixed deposits cannot be withdrawn before their maturity date.");
        }

        // Share capital is redeemed in whole shares so the balance always
        // stays share_count * par_value (mirrors RequestWithdrawalAction).
        if (product != null && product.isShares()) {
            long parValue = product.getParValue() == null ? 0 : product.getParValue();
            if (parValue <= 0 || amount % parValue != 0) {
                throw new IllegalArgumentException(
                        "Share withdrawals must be a whole number of shares (" + parValue + " per share).");
            }
        }

        long held = heldAmount(accountId);
        if (amount <= 0 || amount > account.getBalance() - held) {
            throw new IllegalArgumentException("Requested amount exceeds the available balance.");
        }

        long penaltyAmount = earlyWithdrawalPenalty(account, product, amount);

        try (Connection conn = DatabaseConnection.getConnection()) {
            String id = UUID.randomUUID().toString();
            String now = Instant.now().toString();
            String sql = "INSERT INTO withdrawal_requests (id, savings_account_id, customer_id, amount,"
                    + " penalty_amount, reason, status, requested_by, created_at, updated_at)"
                    + " VALUES (?,?,?,?,?,?,?,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, accountId);
                ps.setString(3, customerId);
                ps.setLong(4, amount);
                ps.setLong(5, penaltyAmount);
                ps.setString(6, reason);
                ps.setString(7, WithdrawalStatus.PENDING.value());
                ps.setString(8, requestedBy);
                ps.setString(9, now);
                ps.setString(10, now);
                ps.executeUpdate();
            }
            return findById(conn, id);
        }
    }

    /** Applies only to target-savings accounts withdrawn from before their matures_at date. */
    private long earlyWithdrawalPenalty(SavingsAccount account, SavingsProduct product, long amount) {
        if (account.getTargetAmount() == null || account.getMaturedAt() != null) {
            return 0;
        }
        if (product == null || !product.isTarget()) {
            return 0;
        }
        return (amount * product.getEarlyWithdrawalPenaltyBps()) / 10_000;
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
        long netCash = request.getAmount() - request.getPenaltyAmount();
        // Resolved before the write block below opens its connection (single-connection pool).
        SavingsProduct product = productService.findById(account.getSavingsProductId());
        long sharesRedeemed = product != null && product.isShares() && product.getParValue() != null && product.getParValue() > 0
                ? request.getAmount() / product.getParValue()
                : 0;

        List<LedgerLine> lines = new ArrayList<>(List.of(
                LedgerLine.debit(account.getLedgerAccountId(), request.getAmount()),
                LedgerLine.credit(chart.branchCash().getId(), netCash)
        ));
        if (request.getPenaltyAmount() > 0) {
            lines.add(LedgerLine.credit(chart.earlyWithdrawalPenaltyIncome().getId(), request.getPenaltyAmount()));
        }

        JournalEntry entry = ledger.post(EntryRequest.of(TransactionType.WITHDRAWAL, lines)
                .paymentMethod(PaymentMethod.CASH)
                .recordedBy(paidBy)
                .description("Withdrawal " + account.getAccountNumber()));

        try (Connection conn = DatabaseConnection.getConnection()) {
            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE savings_accounts SET balance = ?, share_count = ?, updated_at = ? WHERE id = ?")) {
                ps.setLong(1, balanceAfter);
                ps.setLong(2, Math.max(0, account.getShareCount() - sharesRedeemed));
                ps.setString(3, Instant.now().toString());
                ps.setString(4, account.getId());
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
        request.setPenaltyAmount(rs.getLong("penalty_amount"));
        request.setReason(rs.getString("reason"));
        request.setStatus(WithdrawalStatus.fromValue(rs.getString("status")));
        request.setRequestedBy(rs.getString("requested_by"));
        request.setApprovedBy(rs.getString("approved_by"));
        request.setRejectedReason(rs.getString("rejected_reason"));
        request.setPaidEntryId(rs.getString("paid_entry_id"));
        return request;
    }
}
