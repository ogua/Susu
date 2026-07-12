package service;

import db.DatabaseConnection;
import enums.AccountStatus;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import models.SavingsAccount;
import models.SavingsProduct;
import org.json.JSONObject;

/** Opens and lists savings accounts — mirrors App\Actions\Savings\OpenSavingsAccountAction. */
public class SavingsAccountService {

    private final ChartOfAccounts chart = new ChartOfAccounts();
    private final CustomerService customerService = new CustomerService();
    private final SavingsProductService productService = new SavingsProductService();
    private final OutboxService outbox = new OutboxService();

    public SavingsAccount open(String customerId, String productId, String agentId, Long contributionAmountOverride)
            throws SQLException {
        SavingsProduct product = productService.findById(productId);
        if (product == null || !product.isActive()) {
            throw new IllegalArgumentException("This product is no longer offered.");
        }

        String id = UUID.randomUUID().toString();
        String accountNumber;
        try (Connection conn = DatabaseConnection.getConnection()) {
            accountNumber = nextAccountNumber(conn);
        }

        // Resolved via its own connection *before* we open ours below — the
        // SQLite pool is single-connection, so nesting acquisitions here
        // would self-deadlock (this class waiting on itself).
        String ledgerAccountId = chart.savingsLiability(id, accountNumber).getId();

        long contributionAmount = contributionAmountOverride != null
                ? contributionAmountOverride
                : product.getContributionAmount();
        String now = Instant.now().toString();

        SavingsAccount created;
        try (Connection conn = DatabaseConnection.getConnection()) {
            String sql = "INSERT INTO savings_accounts (id, customer_id, savings_product_id, agent_id,"
                    + " ledger_account_id, account_number, contribution_amount, cycle_number, cycle_started_at,"
                    + " contributions_this_cycle, balance, status, opened_at, created_at, updated_at)"
                    + " VALUES (?,?,?,?,?,?,?,1,?,0,0,?,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, customerId);
                ps.setString(3, productId);
                ps.setString(4, agentId);
                ps.setString(5, ledgerAccountId);
                ps.setString(6, accountNumber);
                ps.setLong(7, contributionAmount);
                ps.setString(8, now);
                ps.setString(9, AccountStatus.ACTIVE.value());
                ps.setString(10, now);
                ps.setString(11, now);
                ps.setString(12, now);
                ps.executeUpdate();
            }

            created = findById(conn, id);
        }

        // The local id doubles as client_reference: the backend's OpenSavingsAccountAction
        // uses it as the row's own id when provided, so this account's identity matches
        // across the desktop and server the moment it syncs — collections recorded against
        // it afterwards resolve correctly. Enqueued on its own connection (not nested in
        // the block above) since the SQLite pool is single-connection.
        outbox.enqueueIfHybrid("account.open", new JSONObject()
                .put("customer_id", customerId)
                .put("savings_product_id", productId)
                .put("client_reference", id)
                .put("contribution_amount", contributionAmount));

        return created;
    }

    public SavingsAccount findById(String id) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection()) {
            return findById(conn, id);
        }
    }

    public List<SavingsAccount> findByCustomer(String customerId) throws SQLException {
        List<SavingsAccount> accounts = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM savings_accounts WHERE customer_id = ? ORDER BY opened_at")) {
            ps.setString(1, customerId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    accounts.add(map(rs));
                }
            }
        }
        return accounts;
    }

    public List<SavingsAccount> findAll() throws SQLException {
        List<SavingsAccount> accounts = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM savings_accounts ORDER BY account_number");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                SavingsAccount account = map(rs);
                account.setCustomer(customerService.findById(account.getCustomerId()));
                accounts.add(account);
            }
        }
        return accounts;
    }

    private String nextAccountNumber(Connection conn) throws SQLException {
        int sequence;
        try (PreparedStatement ps = conn.prepareStatement("SELECT COUNT(*) FROM savings_accounts");
             ResultSet rs = ps.executeQuery()) {
            rs.next();
            sequence = rs.getInt(1) + 1;
        }

        String number;
        do {
            number = "S-" + String.format("%06d", sequence);
            sequence++;
        } while (numberExists(conn, number));

        return number;
    }

    private boolean numberExists(Connection conn, String number) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement(
                "SELECT 1 FROM savings_accounts WHERE account_number = ?")) {
            ps.setString(1, number);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next();
            }
        }
    }

    private SavingsAccount findById(Connection conn, String id) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement("SELECT * FROM savings_accounts WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? map(rs) : null;
            }
        }
    }

    private SavingsAccount map(ResultSet rs) throws SQLException {
        SavingsAccount account = new SavingsAccount();
        account.setId(rs.getString("id"));
        account.setCustomerId(rs.getString("customer_id"));
        account.setSavingsProductId(rs.getString("savings_product_id"));
        account.setAgentId(rs.getString("agent_id"));
        account.setLedgerAccountId(rs.getString("ledger_account_id"));
        account.setAccountNumber(rs.getString("account_number"));
        account.setContributionAmount(rs.getLong("contribution_amount"));
        account.setCycleNumber(rs.getInt("cycle_number"));
        account.setCycleStartedAt(rs.getString("cycle_started_at"));
        account.setContributionsThisCycle(rs.getInt("contributions_this_cycle"));
        account.setBalance(rs.getLong("balance"));
        account.setStatus(AccountStatus.fromValue(rs.getString("status")));
        account.setOpenedAt(rs.getString("opened_at"));
        account.setClosedAt(rs.getString("closed_at"));
        return account;
    }
}
