package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.InstallmentStatus;
import enums.LoanFrequency;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.LocalDate;
import java.util.List;
import java.util.UUID;
import models.Customer;
import models.Loan;
import models.LoanInstallment;
import models.LoanProduct;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;

/**
 * Application details (overrides, charges, collateral, guarantors, first
 * repayment date) and the schedule recalculator — mirrors the backend's
 * tests/Feature/Loans/LoanApplicationAndScheduleTest.php.
 */
class LoanApplicationAndScheduleTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final LoanProductService loanProducts = new LoanProductService();
    private final LoanService loans = new LoanService();
    private static final String AGENT_ID = "agent-1";
    private static final String MANAGER_ID = "manager-1";

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-loan-details-test-");
        System.setProperty("user.home", tempHome.toString());

        AppConfig.applySQLiteDefaults();
        new SQLiteProvider().initialize();
    }

    @AfterAll
    static void restoreHome() {
        DatabaseConnection.shutdown();
        System.setProperty("user.home", originalHome);
    }

    private Customer newCustomer(String first) throws Exception {
        Customer customer = new Customer();
        customer.setFirstName(first);
        customer.setLastName("Test");
        customer.setPhone("+233" + UUID.randomUUID().toString().substring(0, 8));
        return customers.register(customer, "setup", null);
    }

    private Loan disbursed(LoanApplicationDetails details) throws Exception {
        Customer customer = newCustomer("Ama");
        LoanProduct product = loanProducts.getOrCreateDefault();
        Loan loan = loans.apply(AGENT_ID, customer.getId(), product.getId(), 400_00, null, null, null, null, null, details);
        loans.approve(loan.getId(), MANAGER_ID);
        return loans.disburse(loan.getId(), MANAGER_ID);
    }

    @Test
    void storesOverridesChargesCollateralAndGuarantors() throws Exception {
        Customer customer = newCustomer("Kofi");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan loan = loans.apply(AGENT_ID, customer.getId(), product.getId(), 400_00, null, null, null, null, null,
                new LoanApplicationDetails(8, LoanFrequency.WEEKLY, 250, null, null, null, "Restock shop",
                        List.of(new LoanApplicationDetails.Charge("Processing fee", 10_00),
                                new LoanApplicationDetails.Charge("Insurance", 5_00)),
                        List.of(new LoanApplicationDetails.Collateral("Vehicle", "Motorbike", 3000_00, "GR-123", null)),
                        List.of(new LoanApplicationDetails.Guarantor(null, "Kwame Asare", "0244000000", "Brother", null, 200_00L))));

        assertEquals(8, loan.getTermPeriodCount());
        assertEquals(LoanFrequency.WEEKLY, loan.getRepaymentFrequency());
        assertEquals(250, loan.getInterestRateBps());
        assertEquals(15_00, loan.getOriginationFeeAmount());
        assertEquals("Restock shop", loan.getPurpose());
        assertEquals("Kwame Asare", loan.getGuarantorName());
        assertEquals(2, loans.findCharges(loan.getId()).size());
        assertEquals(3000_00, loans.findCollaterals(loan.getId()).get(0).estimatedValue());
        assertEquals(200_00L, loans.findGuarantors(loan.getId()).get(0).guaranteedAmount());
    }

    @Test
    void refusesASelfGuarantee() throws Exception {
        Customer customer = newCustomer("Esi");
        LoanProduct product = loanProducts.getOrCreateDefault();

        assertThrows(IllegalArgumentException.class, () -> loans.apply(AGENT_ID, customer.getId(), product.getId(), 400_00,
                null, null, null, null, null,
                new LoanApplicationDetails(null, null, null, null, null, null, null, null, List.of(),
                        List.of(new LoanApplicationDetails.Guarantor(customer.getId(), "Me", null, null, null, null)))));
    }

    @Test
    void schedulesTheFirstInstallmentOnTheChosenDate() throws Exception {
        LocalDate first = LocalDate.now().plusDays(10);

        Loan loan = disbursed(new LoanApplicationDetails(null, LoanFrequency.WEEKLY, null, null, null, first, null, null, List.of(), List.of()));

        List<LoanInstallment> installments = loan.getInstallments();
        assertEquals(first, installments.get(0).getDueDate());
        assertEquals(first.plusWeeks(installments.size() - 1L), installments.get(installments.size() - 1).getDueDate());
    }

    @Test
    void reDatesUnpaidInstallmentsAndKeepsPaidOnes() throws Exception {
        Loan loan = disbursed(new LoanApplicationDetails(4, LoanFrequency.WEEKLY, null, null, null, null, null, null, List.of(), List.of()));
        LoanInstallment first = loan.getInstallments().get(0);
        loans.recordRepayment(loan.getId(), first.totalDue(), MANAGER_ID, null, null);

        LocalDate restart = LocalDate.now().plusMonths(1);
        int rescheduled = loans.recalculateSchedule(loan.getId(), MANAGER_ID, restart, "Customer travelled");

        List<LoanInstallment> after = loans.findInstallments(loan.getId());
        assertEquals(3, rescheduled);
        assertEquals(first.getDueDate(), after.get(0).getDueDate());
        assertEquals(restart, after.get(1).getDueDate());
        assertEquals(restart.plusWeeks(2), after.get(3).getDueDate());
    }

    @Test
    void repairsDatesFromTheLoansOwnRule() throws Exception {
        Loan loan = disbursed(new LoanApplicationDetails(3, LoanFrequency.WEEKLY, null, null, null, null, null, null, List.of(), List.of()));
        List<LoanInstallment> original = loan.getInstallments();

        // Push every installment to the wrong date and back again.
        loans.recalculateSchedule(loan.getId(), MANAGER_ID, LocalDate.of(2020, 1, 1), null);
        loans.recalculateSchedule(loan.getId(), MANAGER_ID, null, null);

        List<LoanInstallment> repaired = loans.findInstallments(loan.getId());
        for (int i = 0; i < original.size(); i++) {
            assertEquals(original.get(i).getDueDate(), repaired.get(i).getDueDate());
            assertEquals(InstallmentStatus.PENDING, repaired.get(i).getStatus());
        }
    }
}
