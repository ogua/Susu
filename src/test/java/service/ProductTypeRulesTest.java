package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.CommissionType;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.LocalDate;
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
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Per-type savings product rules on the standalone engine, mirroring
 * tests/Feature/Savings/ProductTypeRulesTest.php: fixed deposits are funded
 * once with exactly their principal and never carry commission, shares take
 * no susu collections and are withdrawn in whole shares, and a product's
 * term_days fills in a missing maturity date.
 */
class ProductTypeRulesTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final SavingsAccountService accounts = new SavingsAccountService();
    private final SavingsProductService products = new SavingsProductService();
    private final CollectionService collections = new CollectionService();
    private final WithdrawalService withdrawals = new WithdrawalService();
    private final SharesService shares = new SharesService();
    private static final String AGENT_ID = "agent-1";
    private static final String AGENT_NAME = "Agent One";

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-product-rules-test-");
        System.setProperty("user.home", tempHome.toString());

        AppConfig.applySQLiteDefaults();
        new SQLiteProvider().initialize();
    }

    @AfterAll
    static void restoreHome() {
        DatabaseConnection.shutdown();
        System.setProperty("user.home", originalHome);
    }

    private Customer newCustomer() throws Exception {
        Customer customer = new Customer();
        customer.setFirstName("Ama");
        customer.setLastName("Mensah");
        customer.setPhone("+233" + UUID.randomUUID().toString().substring(0, 8));
        return customers.register(customer, "setup", null);
    }

    private SavingsProduct newProduct(String type, CommissionType commission, Integer termDays) throws Exception {
        SavingsProduct product = new SavingsProduct();
        product.setName(type + " " + UUID.randomUUID().toString().substring(0, 4));
        product.setCode("P-" + UUID.randomUUID().toString().substring(0, 6));
        product.setType(type);
        product.setContributionAmount(SavingsProduct.TYPE_SHARES.equals(type) ? 0 : 500);
        product.setCycleLengthDays(31);
        product.setCommissionType(commission);
        product.setCommissionValue(0);
        product.setInterestRateBps(1200);
        product.setTermDays(termDays);
        product.setParValue(SavingsProduct.TYPE_SHARES.equals(type) ? 10_00L : null);
        return products.create(product);
    }

    private SavingsAccount newFixedDeposit() throws Exception {
        SavingsProduct product = newProduct(SavingsProduct.TYPE_FIXED_DEPOSIT, CommissionType.NONE, null);
        return accounts.open(newCustomer().getId(), product.getId(), AGENT_ID, 1_000_00L, null,
                LocalDate.now().plusYears(1).toString());
    }

    @Test
    void clearsCommissionAndTermWhereTheTypeDoesNotUseThem() throws Exception {
        SavingsProduct fixedDeposit = newProduct(SavingsProduct.TYPE_FIXED_DEPOSIT, CommissionType.FIRST_CONTRIBUTION_PER_CYCLE, 90);
        SavingsProduct dailySusu = newProduct(SavingsProduct.TYPE_DAILY_SUSU, CommissionType.NONE, 90);

        assertEquals(CommissionType.NONE, fixedDeposit.getCommissionType());
        assertEquals(90, fixedDeposit.getTermDays());
        assertNull(dailySusu.getTermDays());
    }

    @Test
    void fundsAFixedDepositOnceWithExactlyItsPrincipal() throws Exception {
        SavingsAccount account = newFixedDeposit();

        assertThrows(IllegalArgumentException.class,
                () -> collections.record(AGENT_ID, AGENT_NAME, account.getId(), 2_000_00L, null, null));

        CollectionResult result = collections.record(AGENT_ID, AGENT_NAME, account.getId(), 1_000_00L, null, null);
        assertEquals(0, result.commissionAmount());
        assertEquals(1_000_00L, result.account().getBalance());

        IllegalArgumentException topUp = assertThrows(IllegalArgumentException.class,
                () -> collections.record(AGENT_ID, AGENT_NAME, account.getId(), 1_000_00L, null, null));
        assertTrue(topUp.getMessage().contains("already funded"));
    }

    @Test
    void derivesMaturityFromTheProductTerm() throws Exception {
        SavingsProduct product = newProduct(SavingsProduct.TYPE_FIXED_DEPOSIT, CommissionType.NONE, 180);

        SavingsAccount account = accounts.open(newCustomer().getId(), product.getId(), AGENT_ID, 1_000_00L, null, null);

        assertTrue(account.getMaturesAt().startsWith(LocalDate.now().plusDays(180).toString()));
    }

    @Test
    void rejectsSusuCollectionsOnAShareAccount() throws Exception {
        SavingsProduct product = newProduct(SavingsProduct.TYPE_SHARES, CommissionType.NONE, null);
        SavingsAccount account = accounts.open(newCustomer().getId(), product.getId(), AGENT_ID, null);

        IllegalArgumentException error = assertThrows(IllegalArgumentException.class,
                () -> collections.record(AGENT_ID, AGENT_NAME, account.getId(), 10_00L, null, null));
        assertTrue(error.getMessage().contains("buying shares"));
    }

    @Test
    void redeemsWholeSharesOnly() throws Exception {
        Customer customer = newCustomer();
        SavingsProduct product = newProduct(SavingsProduct.TYPE_SHARES, CommissionType.NONE, null);
        SavingsAccount account = accounts.open(customer.getId(), product.getId(), AGENT_ID, null);
        shares.buyShares(AGENT_ID, AGENT_NAME, account.getId(), 10, null, null);

        assertThrows(IllegalArgumentException.class,
                () -> withdrawals.request(account.getId(), customer.getId(), 15_00L, null, "manager"));

        WithdrawalRequest request = withdrawals.request(account.getId(), customer.getId(), 30_00L, null, "manager");
        withdrawals.approve(request.getId(), "manager-2");
        withdrawals.pay(request.getId(), "manager-2");

        SavingsAccount after = accounts.findById(account.getId());
        assertEquals(7, after.getShareCount());
        assertEquals(70_00L, after.getBalance());
    }
}
