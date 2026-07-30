package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.InstallmentStatus;
import enums.InterestMethod;
import enums.LoanFrequency;
import enums.LoanStatus;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import java.util.UUID;
import models.Customer;
import models.Loan;
import models.LoanInstallment;
import models.LoanProduct;
import models.SavingsAccount;
import models.SavingsProduct;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * End-to-end run of the standalone-mode loan engine: apply, approve,
 * disburse, and repay across a full schedule. Mirrors the shape of
 * tests/Feature/Loans/LoanLifecycleTest.php.
 */
class LoanServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final SavingsAccountService accounts = new SavingsAccountService();
    private final SavingsProductService savingsProducts = new SavingsProductService();
    private final LoanProductService loanProducts = new LoanProductService();
    private final LoanService loans = new LoanService();
    private final LoanEligibilityService eligibility = new LoanEligibilityService();
    private final LedgerService ledger = new LedgerService();
    private static final String AGENT_ID = "agent-1";
    private static final String MANAGER_ID = "manager-1";

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-loan-test-");
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
    void walksALoanThroughApplyApproveDisburseAndFullRepayment() throws Exception {
        Customer customer = newCustomer("Ama", "Serwaa");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 300_00, null, null, null, null, null);
        assertEquals(LoanStatus.APPLIED, applied.getStatus());

        Loan approved = loans.approve(applied.getId(), MANAGER_ID);
        assertEquals(LoanStatus.APPROVED, approved.getStatus());

        Loan disbursed = loans.disburse(approved.getId(), MANAGER_ID);
        assertEquals(LoanStatus.DISBURSED, disbursed.getStatus());
        assertEquals(3, disbursed.getInstallments().size());

        // Flat interest at 3% per period on 300_00 principal = 9_00 per installment.
        long expectedInterestPerPeriod = (300_00L * product.getInterestRateBps()) / 10_000;
        for (LoanInstallment installment : disbursed.getInstallments()) {
            assertEquals(expectedInterestPerPeriod, installment.getInterestDue());
        }
        long principalSum = disbursed.getInstallments().stream().mapToLong(LoanInstallment::getPrincipalDue).sum();
        assertEquals(300_00, principalSum); // rounding remainder absorbed, never lost

        long totalRepayable = disbursed.getTotalRepayable();
        assertEquals(totalRepayable, disbursed.getOutstandingBalance());

        // Pay the first installment in full.
        LoanInstallment firstInstallment = disbursed.getInstallments().get(0);
        LoanRepaymentResult firstPayment = loans.recordRepayment(
                disbursed.getId(), firstInstallment.totalDue(), MANAGER_ID, null, null);
        assertFalse(firstPayment.duplicate());
        assertEquals(LoanStatus.DISBURSED, firstPayment.loan().getStatus());

        List<LoanInstallment> afterFirst = loans.findInstallments(disbursed.getId());
        assertEquals(InstallmentStatus.PAID, afterFirst.get(0).getStatus());
        assertEquals(InstallmentStatus.PENDING, afterFirst.get(1).getStatus());

        // Pay off the rest of the loan.
        long remaining = firstPayment.loan().getOutstandingBalance();
        LoanRepaymentResult finalPayment = loans.recordRepayment(disbursed.getId(), remaining, MANAGER_ID, null, null);
        assertEquals(0, finalPayment.loan().getOutstandingBalance());
        assertEquals(LoanStatus.CLOSED, finalPayment.loan().getStatus());

        for (LoanInstallment installment : loans.findInstallments(disbursed.getId())) {
            assertEquals(InstallmentStatus.PAID, installment.getStatus());
        }

        // The receivable's ledger balance must reconcile to zero, same invariant
        // RecordLoanRepaymentAction relies on server-side.
        var receivable = findLedgerAccountById(finalPayment.loan().getReceivableAccountId());
        assertEquals(0, ledger.recomputeBalance(receivable));
    }

    @Test
    void walksALoanThroughApplyToReject() throws Exception {
        Customer customer = newCustomer("Kofi", "Owusu");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 200_00, null, null, null, null, null);
        Loan rejected = loans.reject(applied.getId(), MANAGER_ID, "Insufficient history");

        assertEquals(LoanStatus.REJECTED, rejected.getStatus());
        assertEquals("Insufficient history", rejected.getRejectionReason());
    }

    @Test
    void refusesToApproveALoanThatIsNotApplied() throws Exception {
        Customer customer = newCustomer("Efua", "Boateng");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 150_00, null, null, null, null, null);
        loans.reject(applied.getId(), MANAGER_ID, "no");

        assertThrows(IllegalStateException.class, () -> loans.approve(applied.getId(), MANAGER_ID));
    }

    @Test
    void isIdempotentWhenTheSameClientReferenceIsReplayed() throws Exception {
        Customer customer = newCustomer("Yaw", "Asante");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 200_00, null, null, null, null, null);
        loans.approve(applied.getId(), MANAGER_ID);
        Loan disbursed = loans.disburse(applied.getId(), MANAGER_ID);

        String ref = UUID.randomUUID().toString();
        LoanRepaymentResult first = loans.recordRepayment(disbursed.getId(), 50_00, MANAGER_ID, ref, null);
        LoanRepaymentResult second = loans.recordRepayment(disbursed.getId(), 50_00, MANAGER_ID, ref, null);

        assertTrue(second.duplicate());
        assertEquals(first.entry().getId(), second.entry().getId());
        assertEquals(disbursed.getOutstandingBalance() - 50_00, loans.findById(disbursed.getId()).getOutstandingBalance());
    }

    @Test
    void usesTheClientReferenceAsTheLoanIdWhenProvided() throws Exception {
        Customer customer = newCustomer("Abena", "Owusu");
        LoanProduct product = loanProducts.getOrCreateDefault();
        String ref = UUID.randomUUID().toString();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 150_00, null, null, null, null, ref);

        assertEquals(ref, applied.getId());
    }

    @Test
    void isIneligibleForANewlyOpenedAccountWithNoCollectionHistory() throws Exception {
        Customer customer = newCustomer("Adjoa", "Mensah");
        SavingsProduct product = savingsProducts.getOrCreateDefault();
        SavingsAccount account = accounts.open(customer.getId(), product.getId(), AGENT_ID, null);

        LoanEligibilityResult result = eligibility.evaluate(account, 100_00);

        assertFalse(result.eligible());
        assertTrue(result.reasons().size() >= 2); // both age and collection-history reasons
    }

    @Test
    void restructuresADisbursedLoanOntoANewProductClosingTheOldLoanAndOpeningAFreshLinkedOne() throws Exception {
        Customer customer = newCustomer("Akosua", "Frimpong");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 300_00, null, null, null, null, null);
        loans.approve(applied.getId(), MANAGER_ID);
        Loan disbursed = loans.disburse(applied.getId(), MANAGER_ID);
        long principalOutstanding = findLedgerAccountById(disbursed.getReceivableAccountId()).getBalance();

        LoanProduct newProduct = new LoanProduct();
        newProduct.setName("Restructure Product");
        newProduct.setCode("LN-RESTR-" + UUID.randomUUID().toString().substring(0, 6));
        newProduct.setInterestMethod(InterestMethod.FLAT);
        newProduct.setInterestRateBps(500);
        newProduct.setTermPeriodCount(6);
        newProduct.setRepaymentFrequency(LoanFrequency.MONTHLY);
        newProduct.setMinAmount(1);
        newProduct.setMaxAmount(1_000_000_00);
        newProduct = loanProducts.create(newProduct);

        Loan newLoan = loans.restructure(disbursed.getId(), MANAGER_ID, newProduct.getId(), "Struggling with the old schedule");

        assertEquals(LoanStatus.DISBURSED, newLoan.getStatus());
        assertEquals(disbursed.getId(), newLoan.getPreviousLoanId());
        assertEquals(principalOutstanding, newLoan.getRolledOverAmount());
        assertEquals(principalOutstanding, newLoan.getPrincipalAmount());
        assertEquals(newProduct.getId(), newLoan.getLoanProductId());
        assertEquals(6, newLoan.getInstallments().size());
        assertEquals(newLoan.getTotalRepayable(), newLoan.getOutstandingBalance());

        Loan oldLoan = loans.findById(disbursed.getId());
        assertEquals(LoanStatus.REFINANCED, oldLoan.getStatus());
        assertEquals(0, oldLoan.getOutstandingBalance());
        assertEquals("restructure", oldLoan.getRefinanceType());
        assertEquals("Struggling with the old schedule", oldLoan.getRefinanceReason());
        assertEquals(disbursed.getOutstandingBalance(), oldLoan.getRefinanceAmount());
        assertEquals(0, findLedgerAccountById(oldLoan.getReceivableAccountId()).getBalance());

        var newReceivable = findLedgerAccountById(newLoan.getReceivableAccountId());
        assertEquals(newLoan.getPrincipalAmount(), newReceivable.getBalance());
    }

    @Test
    void refusesToRestructureALoanThatIsNotDisbursed() throws Exception {
        Customer customer = newCustomer("Kwabena", "Asare");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 200_00, null, null, null, null, null);

        assertThrows(IllegalStateException.class, () -> loans.restructure(applied.getId(), MANAGER_ID, product.getId(), "no"));
    }

    @Test
    void refusesToRestructureALoanWithNoOutstandingPrincipal() throws Exception {
        Customer customer = newCustomer("Adwoa", "Darko");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 200_00, null, null, null, null, null);
        loans.approve(applied.getId(), MANAGER_ID);
        Loan disbursed = loans.disburse(applied.getId(), MANAGER_ID);
        loans.recordRepayment(disbursed.getId(), disbursed.getOutstandingBalance(), MANAGER_ID, null, null);

        assertThrows(IllegalStateException.class, () -> loans.restructure(disbursed.getId(), MANAGER_ID, product.getId(), "no"));
    }

    @Test
    void topsUpADisbursedLoanWithFreshCashRollingTheOldPrincipalIntoTheNewOne() throws Exception {
        Customer customer = newCustomer("Yaa", "Boateng");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 300_00, null, null, null, null, null);
        loans.approve(applied.getId(), MANAGER_ID);
        Loan disbursed = loans.disburse(applied.getId(), MANAGER_ID);
        long principalOutstanding = findLedgerAccountById(disbursed.getReceivableAccountId()).getBalance();

        Loan newLoan = loans.topUp(disbursed.getId(), MANAGER_ID, 200_00, "Customer requested more capital");

        assertEquals(LoanStatus.DISBURSED, newLoan.getStatus());
        assertEquals(disbursed.getId(), newLoan.getPreviousLoanId());
        assertEquals(principalOutstanding, newLoan.getRolledOverAmount());
        assertEquals(principalOutstanding + 200_00, newLoan.getPrincipalAmount());
        assertEquals(disbursed.getLoanProductId(), newLoan.getLoanProductId());

        Loan oldLoan = loans.findById(disbursed.getId());
        assertEquals(LoanStatus.REFINANCED, oldLoan.getStatus());
        assertEquals(0, oldLoan.getOutstandingBalance());
        assertEquals("top_up", oldLoan.getRefinanceType());
        assertEquals(0, findLedgerAccountById(oldLoan.getReceivableAccountId()).getBalance());

        var newReceivable = findLedgerAccountById(newLoan.getReceivableAccountId());
        assertEquals(newLoan.getPrincipalAmount(), newReceivable.getBalance());
    }

    @Test
    void refusesToTopUpALoanThatIsNotDisbursed() throws Exception {
        Customer customer = newCustomer("Kojo", "Nkrumah");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 150_00, null, null, null, null, null);

        assertThrows(IllegalStateException.class, () -> loans.topUp(applied.getId(), MANAGER_ID, 100_00, "no"));
    }

    @Test
    void refusesATopUpAmountThatIsNotPositive() throws Exception {
        Customer customer = newCustomer("Abenaa", "Sarpong");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 150_00, null, null, null, null, null);
        loans.approve(applied.getId(), MANAGER_ID);
        Loan disbursed = loans.disburse(applied.getId(), MANAGER_ID);

        assertThrows(IllegalArgumentException.class, () -> loans.topUp(disbursed.getId(), MANAGER_ID, 0, "no"));
    }

    @Test
    void refusesToTopUpALoanWithAnOverdueInstallment() throws Exception {
        Customer customer = newCustomer("Nana", "Yeboah");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 300_00, null, null, null, null, null);
        loans.approve(applied.getId(), MANAGER_ID);
        Loan disbursed = loans.disburse(applied.getId(), MANAGER_ID);

        LoanInstallment first = disbursed.getInstallments().get(0);
        try (var conn = DatabaseConnection.getConnection();
             var ps = conn.prepareStatement("UPDATE loan_installments SET due_date = ? WHERE id = ?")) {
            ps.setString(1, java.time.LocalDate.now().minusDays(10).toString());
            ps.setString(2, first.getId());
            ps.executeUpdate();
        }
        loans.flagArrears();

        assertThrows(IllegalStateException.class, () -> loans.topUp(disbursed.getId(), MANAGER_ID, 100_00, "no"));
    }

    @Test
    void writesOffADisbursedLoanZeroingTheReceivableAndRecognizingABadDebtExpense() throws Exception {
        Customer customer = newCustomer("Efua", "Danso");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 300_00, null, null, null, null, null);
        loans.approve(applied.getId(), MANAGER_ID);
        Loan disbursed = loans.disburse(applied.getId(), MANAGER_ID);
        long outstandingBeforeWriteOff = disbursed.getOutstandingBalance();
        long principalOutstanding = findLedgerAccountById(disbursed.getReceivableAccountId()).getBalance();

        Loan writtenOff = loans.writeOff(disbursed.getId(), MANAGER_ID, "Borrower absconded");

        assertEquals(LoanStatus.WRITTEN_OFF, writtenOff.getStatus());
        assertEquals(0, writtenOff.getOutstandingBalance());
        assertEquals(outstandingBeforeWriteOff, writtenOff.getWriteOffAmount());
        assertEquals("Borrower absconded", writtenOff.getWriteOffReason());
        assertNotNull(writtenOff.getWrittenOffAt());
        assertEquals(0, findLedgerAccountById(writtenOff.getReceivableAccountId()).getBalance());

        var badDebtExpense = findLedgerAccountByCode("5100-BADDEBT");
        assertEquals(principalOutstanding, badDebtExpense.getBalance());
    }

    @Test
    void refusesToWriteOffALoanThatIsNotDisbursed() throws Exception {
        Customer customer = newCustomer("Kwame", "Adjei");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 200_00, null, null, null, null, null);

        assertThrows(IllegalStateException.class, () -> loans.writeOff(applied.getId(), MANAGER_ID, "no"));
    }

    @Test
    void refusesToWriteOffALoanWithNoOutstandingBalance() throws Exception {
        Customer customer = newCustomer("Adjoa", "Nyarko");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 200_00, null, null, null, null, null);
        loans.approve(applied.getId(), MANAGER_ID);
        Loan disbursed = loans.disburse(applied.getId(), MANAGER_ID);
        loans.recordRepayment(disbursed.getId(), disbursed.getOutstandingBalance(), MANAGER_ID, null, null);

        assertThrows(IllegalStateException.class, () -> loans.writeOff(disbursed.getId(), MANAGER_ID, "no"));
    }

    private models.LedgerAccount findLedgerAccountByCode(String code) throws Exception {
        try (var conn = DatabaseConnection.getConnection();
             var ps = conn.prepareStatement("SELECT * FROM ledger_accounts WHERE code = ?")) {
            ps.setString(1, code);
            try (var rs = ps.executeQuery()) {
                rs.next();
                models.LedgerAccount account = new models.LedgerAccount();
                account.setId(rs.getString("id"));
                account.setCode(rs.getString("code"));
                account.setName(rs.getString("name"));
                account.setType(enums.LedgerAccountType.fromValue(rs.getString("type")));
                account.setBalance(rs.getLong("balance"));
                return account;
            }
        }
    }

    private models.LedgerAccount findLedgerAccountById(String id) throws Exception {
        try (var conn = DatabaseConnection.getConnection();
             var ps = conn.prepareStatement("SELECT * FROM ledger_accounts WHERE id = ?")) {
            ps.setString(1, id);
            try (var rs = ps.executeQuery()) {
                rs.next();
                models.LedgerAccount account = new models.LedgerAccount();
                account.setId(rs.getString("id"));
                account.setCode(rs.getString("code"));
                account.setName(rs.getString("name"));
                account.setType(enums.LedgerAccountType.fromValue(rs.getString("type")));
                account.setBalance(rs.getLong("balance"));
                return account;
            }
        }
    }
}
