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
import static org.junit.jupiter.api.Assertions.assertNotEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertThrows;

/**
 * Fixed-deposit accounts on the standalone engine: opening requires a
 * maturity date and snapshots the product's rate, withdrawal is blocked
 * until matured, and the maturity sweep credits prorated interest exactly
 * once. Mirrors the shape of tests/Feature/Savings/FixedDepositTest.php,
 * including its interest math (intdiv(500000 * 1200 * 181, 10000 * 365) =
 * 29753 pesewas over a 181-day term).
 */
class FixedDepositServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final SavingsAccountService accounts = new SavingsAccountService();
    private final SavingsProductService products = new SavingsProductService();
    private final WithdrawalService withdrawals = new WithdrawalService();
    private final FixedDepositService fixedDeposits = new FixedDepositService();
    private final CollectionService collections = new CollectionService();
    private static final String AGENT_ID = "agent-1";

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-fd-test-");
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

    private SavingsProduct newFixedDepositProduct(int interestRateBps) throws Exception {
        SavingsProduct product = new SavingsProduct();
        product.setName("Fixed Deposit " + interestRateBps);
        product.setCode("FD-" + UUID.randomUUID().toString().substring(0, 6));
        product.setType(SavingsProduct.TYPE_FIXED_DEPOSIT);
        product.setContributionAmount(0);
        product.setCycleLengthDays(31);
        product.setCommissionType(CommissionType.FLAT_PER_CYCLE);
        product.setCommissionValue(0);
        product.setInterestRateBps(interestRateBps);
        return products.create(product);
    }

    @Test
    void requiresAMaturityDateToOpenAFixedDepositAccount() throws Exception {
        Customer customer = newCustomer("Ama", "Serwaa");
        SavingsProduct product = newFixedDepositProduct(1200);

        assertThrows(IllegalArgumentException.class,
                () -> accounts.open(customer.getId(), product.getId(), AGENT_ID, null, null, null));
    }

    @Test
    void opensAFixedDepositAccountAndSnapshotsTheProductsRate() throws Exception {
        Customer customer = newCustomer("Kofi", "Owusu");
        SavingsProduct product = newFixedDepositProduct(1200);

        SavingsAccount account = accounts.open(customer.getId(), product.getId(), AGENT_ID, 5_000_00L,
                null, "2030-01-01");

        assertEquals(1200, account.getInterestRateBps());
        assertEquals("2030-01-01", account.getMaturesAt());
        assertNull(account.getMaturedAt());
        assertEquals(0, account.getBalance());
    }

    @Test
    void doesNotLetALaterProductRateChangeAffectAnAlreadyOpenAccount() throws Exception {
        SavingsProduct product = newFixedDepositProduct(1200);
        Customer customerOne = newCustomer("Yaw", "Mensah");
        SavingsAccount accountOne = accounts.open(customerOne.getId(), product.getId(), AGENT_ID, 1_000_00L,
                null, "2030-01-01");

        product.setInterestRateBps(500);
        products.update(product);

        Customer customerTwo = newCustomer("Abena", "Darko");
        SavingsProduct refreshed = products.findById(product.getId());
        SavingsAccount accountTwo = accounts.open(customerTwo.getId(), refreshed.getId(), AGENT_ID, 1_000_00L,
                null, "2030-01-01");

        assertEquals(1200, accountOne.getInterestRateBps());
        assertEquals(500, accountTwo.getInterestRateBps());
    }

    @Test
    void blocksWithdrawalBeforeTheFixedDepositMatures() throws Exception {
        Customer customer = newCustomer("Efua", "Boateng");
        SavingsProduct product = newFixedDepositProduct(1200);

        SavingsAccount account = accounts.open(customer.getId(), product.getId(), AGENT_ID, 1_000_00L,
                null, "2030-01-01");
        fundBalance(account.getId(), 1_000_00L);

        assertThrows(IllegalStateException.class,
                () -> withdrawals.request(account.getId(), customer.getId(), 1_000_00L, null, "manager-1"));
    }

    @Test
    void maturesAFixedDepositCreditingProratedInterestAndIsIdempotent() throws Exception {
        Customer customer = newCustomer("Kwabena", "Asante");
        SavingsProduct product = newFixedDepositProduct(1200);

        SavingsAccount account = accounts.open(customer.getId(), product.getId(), AGENT_ID, 5_000_00L,
                null, LocalDate.now().minusDays(1).toString());
        // Funds via CollectionService (not a raw balance UPDATE) so the account's
        // balance column and its shadow ledger_accounts row stay in sync, exactly
        // as production always keeps them.
        collections.record(AGENT_ID, "Agent One", account.getId(), 500_000L, null, null);
        // Noon UTC keeps the local-zone conversion inside FixedDepositService's
        // LocalDate.ofInstant(..., ZoneId.systemDefault()) on the same calendar
        // day regardless of the machine's timezone offset.
        backdateTerm(account.getId(), "2026-01-01T12:00:00Z", "2026-07-01");

        // intdiv(500000 * 1200 * 181, 10000 * 365) = 29753 pesewas.
        long expectedInterest = 29_753L;
        // savingsInterestExpense is a shared system account across this whole
        // test class, so assert its delta, not an absolute value.
        long interestExpenseBefore = ledgerAccountBalance(new ChartOfAccounts().savingsInterestExpense().getId());

        fixedDeposits.matureFixedDeposits();

        SavingsAccount matured = accounts.findById(account.getId());
        assertNotNull(matured.getMaturedAt());
        assertEquals(500_000L + expectedInterest, matured.getBalance());

        long interestExpenseBalance = ledgerAccountBalance(new ChartOfAccounts().savingsInterestExpense().getId());
        long accountLedgerBalance = ledgerAccountBalance(matured.getLedgerAccountId());
        assertEquals(expectedInterest, interestExpenseBalance - interestExpenseBefore);
        assertEquals(500_000L + expectedInterest, accountLedgerBalance);

        // Idempotent re-run posts nothing more.
        fixedDeposits.matureFixedDeposits();
        SavingsAccount reRun = accounts.findById(account.getId());
        assertEquals(matured.getBalance(), reRun.getBalance());
        assertEquals(interestExpenseBalance,
                ledgerAccountBalance(new ChartOfAccounts().savingsInterestExpense().getId()));

        // Post-maturity withdrawal is a completely ordinary, zero-penalty flow.
        WithdrawalRequest request = withdrawals.request(reRun.getId(), customer.getId(), reRun.getBalance(),
                null, "manager-1");
        assertEquals(0, request.getPenaltyAmount());
    }

    @Test
    void earnsInterestOnlyFromTheFundingDateNotFromOpening() throws Exception {
        Customer customer = newCustomer("Akua", "Boateng");
        SavingsProduct product = newFixedDepositProduct(1200);

        SavingsAccount account = accounts.open(customer.getId(), product.getId(), AGENT_ID, 5_000_00L,
                null, LocalDate.now().minusDays(1).toString());
        collections.record(AGENT_ID, "Agent One", account.getId(), 500_000L, null, null);
        backdateTerm(account.getId(), "2026-01-01T12:00:00Z", "2026-03-01", "2026-07-01");

        fixedDeposits.matureFixedDeposits();

        // 122 days funded: intdiv(500000 * 1200 * 122, 10000 * 365) = 20054 pesewas.
        assertEquals(500_000L + 20_054L, accounts.findById(account.getId()).getBalance());
    }

    @Test
    void leavesNonFixedDepositAccountsAlone() throws Exception {
        Customer customer = newCustomer("Adjoa", "Nyarko");
        SavingsProduct dailyProduct = products.getOrCreateDefault();
        SavingsAccount account = accounts.open(customer.getId(), dailyProduct.getId(), AGENT_ID, null);

        int matured = fixedDeposits.matureFixedDeposits();

        assertEquals(0, matured);
        assertNull(accounts.findById(account.getId()).getMaturedAt());
        assertNotEquals(SavingsProduct.TYPE_FIXED_DEPOSIT, dailyProduct.getType());
    }

    private void fundBalance(String accountId, long balance) throws Exception {
        try (var conn = DatabaseConnection.getConnection();
             var ps = conn.prepareStatement("UPDATE savings_accounts SET balance = ? WHERE id = ?")) {
            ps.setLong(1, balance);
            ps.setString(2, accountId);
            ps.executeUpdate();
        }
    }

    /** Backdates opening and funding to the same day. */
    private void backdateTerm(String accountId, String openedAt, String maturesAt) throws Exception {
        backdateTerm(accountId, openedAt, openedAt.substring(0, 10), maturesAt);
    }

    private void backdateTerm(String accountId, String openedAt, String fundedOn, String maturesAt) throws Exception {
        try (var conn = DatabaseConnection.getConnection();
             var ps = conn.prepareStatement(
                     "UPDATE savings_accounts SET opened_at = ?, cycle_started_at = ?, matures_at = ? WHERE id = ?")) {
            ps.setString(1, openedAt);
            ps.setString(2, fundedOn);
            ps.setString(3, maturesAt);
            ps.setString(4, accountId);
            ps.executeUpdate();
        }
    }

    private long ledgerAccountBalance(String ledgerAccountId) throws Exception {
        try (var conn = DatabaseConnection.getConnection();
             var ps = conn.prepareStatement("SELECT balance FROM ledger_accounts WHERE id = ?")) {
            ps.setString(1, ledgerAccountId);
            try (var rs = ps.executeQuery()) {
                rs.next();
                return rs.getLong("balance");
            }
        }
    }
}
