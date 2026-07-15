package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import java.nio.file.Files;
import java.nio.file.Path;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.time.LocalDate;
import java.util.List;
import java.util.UUID;
import models.Customer;
import models.DefaulterRow;
import models.Loan;
import models.LoanInstallment;
import models.LoanProduct;
import models.LedgerAccount;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Standalone-mode mirror of the backend's TrialBalance/DefaultersReport/
 * CashPosition Filament page tests.
 */
class ReportServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final LoanProductService loanProducts = new LoanProductService();
    private final LoanService loans = new LoanService();
    private final ChartOfAccounts chart = new ChartOfAccounts();
    private final LedgerService ledger = new LedgerService();
    private final ReportService reports = new ReportService();
    private static final String AGENT_ID = "agent-report-1";
    private static final String MANAGER_ID = "manager-report-1";

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-report-test-");
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
    void trialBalanceListsEveryLedgerAccountSplitByNormalBalance() throws Exception {
        LedgerAccount cash = chart.agentCash(AGENT_ID, "Trial Balance Agent");
        ledger.post(EntryRequest.of(enums.TransactionType.ADJUSTMENT,
                        List.of(new LedgerLine(cash.getId(), 500_00, 0, null),
                                new LedgerLine(chart.commissionIncome().getId(), 0, 500_00, null)))
                .origin("desktop"));

        List<LedgerAccount> accounts = reports.trialBalance();

        assertTrue(accounts.stream().anyMatch(a -> a.getId().equals(cash.getId()) && a.getBalance() == 500_00));

        long totalDebits = accounts.stream().filter(a -> a.getType().isNormalBalanceDebit())
                .mapToLong(LedgerAccount::getBalance).sum();
        long totalCredits = accounts.stream().filter(a -> !a.getType().isNormalBalanceDebit())
                .mapToLong(LedgerAccount::getBalance).sum();
        assertEquals(totalDebits, totalCredits);
    }

    @Test
    void cashPositionListsBranchCashAndEveryAgentCashAccount() throws Exception {
        chart.branchCash();
        LedgerAccount agentCash = chart.agentCash("agent-cash-position", "Cash Position Agent");

        List<LedgerAccount> accounts = reports.cashPosition();

        assertTrue(accounts.stream().anyMatch(a -> a.getCode().equals("CASH-MAIN")));
        assertTrue(accounts.stream().anyMatch(a -> a.getId().equals(agentCash.getId())));
    }

    @Test
    void flagArrearsMarksAPastGraceInstallmentOverdueAndItAppearsInDefaulters() throws Exception {
        Customer customer = newCustomer("Abena", "Defaulter");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 300_00, null, null, null, null, null);
        loans.approve(applied.getId(), MANAGER_ID);
        Loan disbursed = loans.disburse(applied.getId(), MANAGER_ID);

        LoanInstallment firstInstallment = disbursed.getInstallments().get(0);
        LocalDate pastDueDate = LocalDate.now().minusDays(product.getGracePeriodDays() + 5);
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("UPDATE loan_installments SET due_date = ? WHERE id = ?")) {
            ps.setString(1, pastDueDate.toString());
            ps.setString(2, firstInstallment.getId());
            ps.executeUpdate();
        }

        int flagged = loans.flagArrears();
        assertTrue(flagged >= 1);

        List<LoanInstallment> refreshed = loans.findInstallments(disbursed.getId());
        assertEquals(enums.InstallmentStatus.OVERDUE, refreshed.get(0).getStatus());

        List<DefaulterRow> defaulters = reports.defaulters();
        assertTrue(defaulters.stream().anyMatch(row -> row.getLoanNumber().equals(disbursed.getLoanNumber())));
    }

    @Test
    void flagArrearsIsANoOpForAnInstallmentStillWithinItsGracePeriod() throws Exception {
        Customer customer = newCustomer("Kwame", "OnTime");
        LoanProduct product = loanProducts.getOrCreateDefault();

        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 150_00, null, null, null, null, null);
        loans.approve(applied.getId(), MANAGER_ID);
        loans.disburse(applied.getId(), MANAGER_ID);

        loans.flagArrears();

        List<LoanInstallment> installments = loans.findInstallments(applied.getId());
        assertTrue(installments.stream().noneMatch(i -> i.getStatus() == enums.InstallmentStatus.OVERDUE));
    }
}
