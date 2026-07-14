package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.WithdrawalStatus;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.UUID;
import models.Customer;
import models.SavingsAccount;
import models.SavingsProduct;
import models.WithdrawalRequest;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertThrows;

/**
 * Target-savings accounts on the standalone engine: opening requires a goal
 * and maturity date, progress tracks the balance against the target, and an
 * early withdrawal is penalized (waived once matured). Mirrors the shape of
 * tests/Feature/Savings/TargetSavingsTest.php.
 */
class TargetSavingsServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final SavingsAccountService accounts = new SavingsAccountService();
    private final SavingsProductService products = new SavingsProductService();
    private final WithdrawalService withdrawals = new WithdrawalService();
    private static final String AGENT_ID = "agent-1";

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-target-test-");
        System.setProperty("user.home", tempHome.toString());

        AppConfig.applySQLiteDefaults();
        new SQLiteProvider().initialize();
    }

    @AfterAll
    static void restoreHome() {
        DatabaseConnection.shutdown();
        System.setProperty("user.home", originalHome);
    }

    private Customer newCustomer(String first, String last) throws Exception {
        Customer customer = new Customer();
        customer.setFirstName(first);
        customer.setLastName(last);
        customer.setPhone("+233" + UUID.randomUUID().toString().substring(0, 8));
        return customers.register(customer, "setup", null);
    }

    @Test
    void requiresATargetAmountAndMaturityDateToOpenATargetAccount() throws Exception {
        Customer customer = newCustomer("Ama", "Serwaa");
        SavingsProduct target = products.getOrCreateDefaultTarget();

        assertThrows(IllegalArgumentException.class,
                () -> accounts.open(customer.getId(), target.getId(), AGENT_ID, null, null, null));
    }

    @Test
    void opensATargetAccountAndTracksProgress() throws Exception {
        Customer customer = newCustomer("Kofi", "Owusu");
        SavingsProduct target = products.getOrCreateDefaultTarget();

        SavingsAccount account = accounts.open(customer.getId(), target.getId(), AGENT_ID, null,
                500_000L, "2027-01-01");

        assertEquals(500_000L, account.getTargetAmount());
        assertEquals("2027-01-01", account.getMaturesAt());
        assertNull(account.getMaturedAt());
        assertEquals(0.0, account.targetProgressPercent());
    }

    @Test
    void chargesAnEarlyWithdrawalPenaltyBeforeTheTargetMatures() throws Exception {
        Customer customer = newCustomer("Efua", "Boateng");
        SavingsProduct target = products.getOrCreateDefaultTarget();
        // 10% penalty per getOrCreateDefaultTarget()'s default.

        SavingsAccount account = accounts.open(customer.getId(), target.getId(), AGENT_ID, 500L,
                1_000_00L, "2027-01-01");

        // Fund the account directly (this test targets withdrawal penalty math,
        // not collection posting, which CollectionServiceTest already covers).
        try (var conn = DatabaseConnection.getConnection();
             var ps = conn.prepareStatement("UPDATE savings_accounts SET balance = 1000 WHERE id = ?")) {
            ps.setString(1, account.getId());
            ps.executeUpdate();
        }

        WithdrawalRequest request = withdrawals.request(account.getId(), customer.getId(), 1000, null, "manager-1");
        assertEquals(100, request.getPenaltyAmount()); // 10% of 1000

        try (var conn = DatabaseConnection.getConnection();
             var ps = conn.prepareStatement("UPDATE withdrawal_requests SET status = ?, approved_by = ? WHERE id = ?")) {
            ps.setString(1, WithdrawalStatus.APPROVED.value());
            ps.setString(2, "manager-1");
            ps.setString(3, request.getId());
            ps.executeUpdate();
        }

        WithdrawalRequest paid = withdrawals.pay(request.getId(), "manager-1");
        assertEquals(WithdrawalStatus.PAID, paid.getStatus());

        var penaltyIncome = new ChartOfAccounts().earlyWithdrawalPenaltyIncome();
        assertEquals(100, penaltyIncome.getBalance());
    }

    @Test
    void chargesNoPenaltyOnceTheAccountHasMatured() throws Exception {
        Customer customer = newCustomer("Yaw", "Asante");
        SavingsProduct target = products.getOrCreateDefaultTarget();

        SavingsAccount account = accounts.open(customer.getId(), target.getId(), AGENT_ID, 500L,
                1_000_00L, "2027-01-01");

        try (var conn = DatabaseConnection.getConnection();
             var ps = conn.prepareStatement("UPDATE savings_accounts SET balance = 1000, matured_at = ? WHERE id = ?")) {
            ps.setString(1, java.time.Instant.now().toString());
            ps.setString(2, account.getId());
            ps.executeUpdate();
        }

        WithdrawalRequest request = withdrawals.request(account.getId(), customer.getId(), 1000, null, "manager-1");
        assertEquals(0, request.getPenaltyAmount());
    }
}
