package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.CommissionType;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.UUID;
import models.Customer;
import models.SavingsAccount;
import models.SavingsProduct;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Shares accounts on the standalone engine: opening starts at zero shares
 * and zero balance, buying shares posts Dr agent cash / Cr the account's
 * savings liability for shares * par value, is idempotent on client
 * reference, and never charges an early-withdrawal penalty. Mirrors the
 * shape of tests/Feature/Savings/SharesTest.php.
 */
class SharesServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final SavingsAccountService accounts = new SavingsAccountService();
    private final SavingsProductService products = new SavingsProductService();
    private final WithdrawalService withdrawals = new WithdrawalService();
    private final SharesService shares = new SharesService();
    private static final String AGENT_ID = "agent-1";
    private static final String AGENT_NAME = "Agent One";

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-shares-test-");
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

    private SavingsProduct newSharesProduct(long parValue) throws Exception {
        SavingsProduct product = new SavingsProduct();
        product.setName("Shares " + parValue);
        product.setCode("SH-" + UUID.randomUUID().toString().substring(0, 6));
        product.setType(SavingsProduct.TYPE_SHARES);
        product.setContributionAmount(0);
        product.setCycleLengthDays(31);
        product.setCommissionType(CommissionType.FLAT_PER_CYCLE);
        product.setCommissionValue(0);
        product.setParValue(parValue);
        return products.create(product);
    }

    @Test
    void opensASharesAccountWithZeroBalanceAndZeroShares() throws Exception {
        Customer customer = newCustomer("Ama", "Serwaa");
        SavingsProduct product = newSharesProduct(10_00);

        SavingsAccount account = accounts.open(customer.getId(), product.getId(), AGENT_ID, null);

        assertEquals(0, account.getBalance());
        assertEquals(0, account.getShareCount());
        assertNull(account.getTargetAmount());
        assertNull(account.getMaturesAt());
    }

    @Test
    void buysSharesIncrementingShareCountAndBalanceBySharesTimesParValue() throws Exception {
        Customer customer = newCustomer("Kofi", "Owusu");
        SavingsProduct product = newSharesProduct(10_00);
        SavingsAccount account = accounts.open(customer.getId(), product.getId(), AGENT_ID, null);
        // agentCash is a shared system account across this whole test class
        // (same AGENT_ID everywhere), so assert its delta, not an absolute value.
        long agentCashBefore = ledgerAccountBalance(new ChartOfAccounts().agentCash(AGENT_ID, AGENT_NAME).getId());

        SharesService.SharesResult result = shares.buyShares(AGENT_ID, AGENT_NAME, account.getId(), 50, null, null);

        assertFalse(result.duplicate());
        assertEquals(50, result.account().getShareCount());
        assertEquals(500_00L, result.account().getBalance()); // 50 shares * 10_00 par value

        long agentCashAfter = ledgerAccountBalance(new ChartOfAccounts().agentCash(AGENT_ID, AGENT_NAME).getId());
        long accountLedgerBalance = ledgerAccountBalance(result.account().getLedgerAccountId());
        assertEquals(500_00L, agentCashAfter - agentCashBefore);
        assertEquals(500_00L, accountLedgerBalance);
    }

    @Test
    void rejectsAZeroOrNegativeSharePurchase() throws Exception {
        Customer customer = newCustomer("Efua", "Boateng");
        SavingsProduct product = newSharesProduct(10_00);
        SavingsAccount account = accounts.open(customer.getId(), product.getId(), AGENT_ID, null);

        assertThrows(IllegalArgumentException.class,
                () -> shares.buyShares(AGENT_ID, AGENT_NAME, account.getId(), 0, null, null));
    }

    @Test
    void rejectsASharePurchaseOnANonSharesAccount() throws Exception {
        Customer customer = newCustomer("Yaw", "Asante");
        SavingsProduct dailyProduct = products.getOrCreateDefault();
        SavingsAccount account = accounts.open(customer.getId(), dailyProduct.getId(), AGENT_ID, null);

        assertThrows(IllegalArgumentException.class,
                () -> shares.buyShares(AGENT_ID, AGENT_NAME, account.getId(), 10, null, null));
    }

    @Test
    void isIdempotentWhenTheSameClientReferenceIsReplayed() throws Exception {
        Customer customer = newCustomer("Abena", "Darko");
        SavingsProduct product = newSharesProduct(10_00);
        SavingsAccount account = accounts.open(customer.getId(), product.getId(), AGENT_ID, null);

        String ref = UUID.randomUUID().toString();
        SharesService.SharesResult first = shares.buyShares(AGENT_ID, AGENT_NAME, account.getId(), 20, ref, null);
        SharesService.SharesResult second = shares.buyShares(AGENT_ID, AGENT_NAME, account.getId(), 20, ref, null);

        assertTrue(second.duplicate());
        assertEquals(first.entry().getId(), second.entry().getId());
        assertEquals(20, accounts.findById(account.getId()).getShareCount()); // only applied once
    }

    @Test
    void chargesNoEarlyWithdrawalPenaltyOnASharesAccount() throws Exception {
        Customer customer = newCustomer("Kwabena", "Mensah");
        SavingsProduct product = newSharesProduct(10_00);
        SavingsAccount account = accounts.open(customer.getId(), product.getId(), AGENT_ID, null);
        shares.buyShares(AGENT_ID, AGENT_NAME, account.getId(), 10, null, null);

        var request = withdrawals.request(account.getId(), customer.getId(), 50_00L, null, "manager-1");

        assertEquals(0, request.getPenaltyAmount());
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
