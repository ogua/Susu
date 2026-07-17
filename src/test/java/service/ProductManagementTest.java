package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.CommissionType;
import enums.InterestMethod;
import enums.LoanFrequency;
import java.nio.file.Files;
import java.nio.file.Path;
import models.LoanProduct;
import models.SavingsProduct;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

/** Covers the product-management additions behind the desktop Products screen. */
class ProductManagementTest {

    private static String originalHome;
    private final SavingsProductService savingsProducts = new SavingsProductService();
    private final LoanProductService loanProducts = new LoanProductService();

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-products-test-");
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
    void createsAndDeactivatesASavingsProduct() throws Exception {
        SavingsProduct product = new SavingsProduct();
        product.setName("Weekly Susu");
        product.setCode("WS-100");
        product.setType("daily_susu");
        product.setContributionAmount(1000);
        product.setCycleLengthDays(28);
        product.setCommissionType(CommissionType.FLAT_PER_CYCLE);
        product.setCommissionValue(200);

        SavingsProduct stored = savingsProducts.create(product);
        assertEquals("Weekly Susu", stored.getName());
        assertTrue(stored.isActive());
        assertTrue(savingsProducts.findAll().stream().anyMatch(p -> p.getId().equals(stored.getId())));

        savingsProducts.setActive(stored.getId(), false);
        assertFalse(savingsProducts.findById(stored.getId()).isActive());
        assertTrue(savingsProducts.findActive().stream().noneMatch(p -> p.getId().equals(stored.getId())));
        // Still listed for management even while inactive.
        assertTrue(savingsProducts.findAll().stream().anyMatch(p -> p.getId().equals(stored.getId())));
    }

    @Test
    void createsAndDeactivatesALoanProduct() throws Exception {
        LoanProduct product = new LoanProduct();
        product.setName("Business Loan");
        product.setCode("BL-100");
        product.setInterestMethod(InterestMethod.REDUCING_BALANCE);
        product.setInterestRateBps(250);
        product.setTermPeriodCount(6);
        product.setRepaymentFrequency(LoanFrequency.MONTHLY);
        product.setOriginationFeeAmount(0);
        product.setPenaltyRateBps(0);
        product.setGracePeriodDays(3);
        product.setMinAmount(500_00);
        product.setMaxAmount(10_000_00);

        LoanProduct stored = loanProducts.create(product);
        assertEquals("Business Loan", stored.getName());
        assertEquals(250, stored.getInterestRateBps());
        assertTrue(stored.isActive());

        loanProducts.setActive(stored.getId(), false);
        assertFalse(loanProducts.findById(stored.getId()).isActive());
        assertTrue(loanProducts.findAll().stream().anyMatch(p -> p.getId().equals(stored.getId())));
    }
}
