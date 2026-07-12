package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.AgentSummaryStatus;
import enums.WithdrawalStatus;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.UUID;
import models.AgentDailySummary;
import models.Customer;
import models.SavingsAccount;
import models.SavingsProduct;
import models.WithdrawalRequest;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * End-to-end run of the standalone-mode operations engine: register a
 * customer, open a daily-susu account, record collections through a cycle
 * rollover, then request/approve/pay a withdrawal. Mirrors the shape of
 * tests/Feature/Api/V1/Agent/CollectionTest.php and WithdrawalFlowTest.php.
 */
class CollectionServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final SavingsAccountService accounts = new SavingsAccountService();
    private final SavingsProductService products = new SavingsProductService();
    private final CollectionService collections = new CollectionService();
    private final WithdrawalService withdrawals = new WithdrawalService();
    private final AgentDailySummaryService summaries = new AgentDailySummaryService();
    private static final String AGENT_ID = "agent-1";
    private static final String AGENT_NAME = "Test Agent";

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-collection-test-");
        System.setProperty("user.home", tempHome.toString());

        AppConfig.applySQLiteDefaults();
        new SQLiteProvider().initialize();
    }

    @AfterAll
    static void restoreHome() {
        DatabaseConnection.shutdown();
        System.setProperty("user.home", originalHome);
    }

    @Test
    void recordsCollectionsThroughACycleRolloverThenPaysAWithdrawal() throws Exception {
        Customer customer = new Customer();
        customer.setFirstName("Ama");
        customer.setLastName("Mensah");
        customer.setPhone("+233" + UUID.randomUUID().toString().substring(0, 8));
        customer = customers.register(customer, "setup", null);

        SavingsProduct product = products.getOrCreateDefault();
        SavingsAccount account = accounts.open(customer.getId(), product.getId(), AGENT_ID, null);

        // Day 1: deposit == contribution amount, entirely absorbed as commission.
        CollectionResult first = collections.record(AGENT_ID, AGENT_NAME, account.getId(),
                account.getContributionAmount(), null, null);
        assertEquals(account.getContributionAmount(), first.commissionAmount());
        assertEquals(0, first.account().getBalance());
        assertEquals(1, first.account().getContributionsThisCycle());

        // Days 2-3: no further commission.
        CollectionResult second = collections.record(AGENT_ID, AGENT_NAME, account.getId(),
                account.getContributionAmount(), null, null);
        assertEquals(0, second.commissionAmount());
        assertEquals(account.getContributionAmount(), second.account().getBalance());

        // Idempotency: replaying the same client_reference must not double-post.
        String ref = UUID.randomUUID().toString();
        CollectionResult third = collections.record(AGENT_ID, AGENT_NAME, account.getId(),
                account.getContributionAmount(), ref, null);
        CollectionResult replay = collections.record(AGENT_ID, AGENT_NAME, account.getId(),
                account.getContributionAmount(), ref, null);
        assertTrue(replay.duplicate());
        assertEquals(third.entry().getId(), replay.entry().getId());
        assertEquals(2 * account.getContributionAmount(), replay.account().getBalance());

        // Agent day sheet: expected cash equals the ledger, cash-in-hand balance.
        AgentDailySummary today = summaries.today(AGENT_ID);
        assertEquals(3, today.getCollectionsCount());
        long expected = summaries.expectedCash(AGENT_ID, AGENT_NAME);
        assertEquals(3 * account.getContributionAmount(), expected);

        // Withdrawal: request -> approve -> pay, moving cash from branch to customer liability.
        WithdrawalRequest request = withdrawals.request(account.getId(), customer.getId(),
                account.getContributionAmount(), "personal emergency", "customer-1");
        assertEquals(WithdrawalStatus.PENDING, request.getStatus());

        withdrawals.approve(request.getId(), "manager-1");
        WithdrawalRequest paid = withdrawals.pay(request.getId(), "manager-1");
        assertEquals(WithdrawalStatus.PAID, paid.getStatus());

        SavingsAccount afterWithdrawal = accounts.findById(account.getId());
        assertEquals(account.getContributionAmount(), afterWithdrawal.getBalance());
    }

    @Test
    void rejectsAWithdrawalAboveTheAvailableBalance() throws Exception {
        Customer customer = new Customer();
        customer.setFirstName("Kofi");
        customer.setLastName("Owusu");
        customer.setPhone("+233" + UUID.randomUUID().toString().substring(0, 8));
        customer = customers.register(customer, "setup", null);

        SavingsProduct product = products.getOrCreateDefault();
        SavingsAccount account = accounts.open(customer.getId(), product.getId(), AGENT_ID, null);

        Customer finalCustomer = customer;
        assertThrows(IllegalArgumentException.class,
                () -> withdrawals.request(account.getId(), finalCustomer.getId(), 100_000, null, "customer-1"));
    }
}
