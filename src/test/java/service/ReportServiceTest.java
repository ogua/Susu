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
import models.JournalEntry;
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
    void collectionsReportListsCollectionEntriesAndHonoursTheDateRange() throws Exception {
        LedgerAccount cash = chart.agentCash("agent-collections-report", "Collections Report Agent");
        LedgerAccount liability = chart.savingsLiability("acct-collections-report", "SA-CR-001");

        JournalEntry entry = ledger.post(EntryRequest.of(enums.TransactionType.COLLECTION,
                        List.of(new LedgerLine(cash.getId(), 700, 0, null),
                                new LedgerLine(liability.getId(), 0, 700, null)))
                .origin("desktop")
                .description("Collections report test entry"));

        List<models.CollectionsRow> all = reports.collections(null, null);
        assertTrue(all.stream().anyMatch(row ->
                row.getReference().equals(entry.getReference()) && row.getAmount() == 700));

        List<models.CollectionsRow> future = reports.collections(LocalDate.now().plusDays(1), null);
        assertTrue(future.stream().noneMatch(row -> row.getReference().equals(entry.getReference())));
    }

    @Test
    void loanPortfolioListsLoansAndFiltersByStatus() throws Exception {
        Customer customer = newCustomer("Yaa", "Portfolio");
        LoanProduct product = loanProducts.getOrCreateDefault();
        Loan applied = loans.apply(AGENT_ID, customer.getId(), product.getId(), 200_00, null, null, null, null, null);

        List<models.LoanPortfolioRow> all = reports.loanPortfolio(null, null, null);
        assertTrue(all.stream().anyMatch(row -> row.getLoanNumber().equals(applied.getLoanNumber())));

        List<models.LoanPortfolioRow> disbursedOnly = reports.loanPortfolio(null, null, "disbursed");
        assertTrue(disbursedOnly.stream().noneMatch(row -> row.getLoanNumber().equals(applied.getLoanNumber())));
    }

    @Test
    void agentPerformanceAggregatesDaySheets() throws Exception {
        String agentId = "agent-performance-report";
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "INSERT INTO agent_daily_summaries (id, agent_id, summary_date, collections_total,"
                     + " collections_count, expected_cash, status, created_at, updated_at)"
                     + " VALUES (?,?,?,?,?,?,?,?,?)")) {
            ps.setString(1, UUID.randomUUID().toString());
            ps.setString(2, agentId);
            ps.setString(3, LocalDate.now().toString());
            ps.setLong(4, 1200);
            ps.setInt(5, 3);
            ps.setLong(6, 1200);
            ps.setString(7, "open");
            ps.setString(8, java.time.Instant.now().toString());
            ps.setString(9, java.time.Instant.now().toString());
            ps.executeUpdate();
        }

        List<models.AgentPerformanceRow> rows = reports.agentPerformance(LocalDate.now(), LocalDate.now());
        models.AgentPerformanceRow row = rows.stream()
                .filter(r -> r.getCollectionsTotal() == 1200)
                .findFirst().orElseThrow();
        assertEquals(3, row.getCollectionsCount());
        assertEquals(1, row.getDaysWorked());
        assertEquals(1, row.getUnreconciledDays());
    }

    @Test
    void withdrawalsReportListsRequestsWithStatusFilter() throws Exception {
        Customer customer = newCustomer("Efua", "Withdrawal");
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "INSERT INTO withdrawal_requests (id, savings_account_id, customer_id, amount, status,"
                     + " created_at, updated_at) VALUES (?,?,?,?,?,?,?)")) {
            ps.setString(1, UUID.randomUUID().toString());
            ps.setString(2, "acct-withdrawal-report");
            ps.setString(3, customer.getId());
            ps.setLong(4, 350);
            ps.setString(5, "pending");
            ps.setString(6, java.time.Instant.now().toString());
            ps.setString(7, java.time.Instant.now().toString());
            ps.executeUpdate();
        }

        List<models.WithdrawalRow> pending = reports.withdrawals(null, null, "pending");
        assertTrue(pending.stream().anyMatch(row ->
                row.getAmount() == 350 && row.getCustomerName().equals(customer.fullName())));

        List<models.WithdrawalRow> paid = reports.withdrawals(null, null, "paid");
        assertTrue(paid.stream().noneMatch(row -> row.getAmount() == 350));
    }

    @Test
    void groupsReportListsEveryGroupWithMembershipCounts() throws Exception {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "INSERT INTO groups_table (id, name, code, contribution_amount, frequency, status,"
                     + " created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)")) {
            ps.setString(1, UUID.randomUUID().toString());
            ps.setString(2, "Report Test Group");
            ps.setString(3, "GRP-RT1");
            ps.setLong(4, 1000);
            ps.setString(5, "monthly");
            ps.setString(6, "draft");
            ps.setString(7, java.time.Instant.now().toString());
            ps.setString(8, java.time.Instant.now().toString());
            ps.executeUpdate();
        }

        List<models.GroupReportRow> rows = reports.groupsReport();
        models.GroupReportRow row = rows.stream()
                .filter(r -> r.getCode().equals("GRP-RT1"))
                .findFirst().orElseThrow();
        assertEquals(0, row.getMembersCount());
        assertEquals("—", row.getCurrentRound());
    }

    @Test
    void customerBalancesListsOpenAccountsWithTheirBalances() throws Exception {
        Customer customer = newCustomer("Adjoa", "Balances");
        models.SavingsProduct product = new SavingsProductService().getOrCreateDefault();
        models.SavingsAccount account = new SavingsAccountService()
                .open(customer.getId(), product.getId(), AGENT_ID, null);

        List<models.CustomerBalanceRow> rows = reports.customerBalances();
        assertTrue(rows.stream().anyMatch(row ->
                row.getAccountNumber().equals(account.getAccountNumber())
                        && row.getCustomerName().equals(customer.fullName())));
    }

    @Test
    void accountLedgerComputesOpeningAndRunningBalances() throws Exception {
        LedgerAccount cash = chart.agentCash("agent-ledger-report", "Ledger Report Agent");
        LedgerAccount liability = chart.savingsLiability("acct-ledger-report", "SA-LR-001");

        ledger.post(EntryRequest.of(enums.TransactionType.COLLECTION,
                        List.of(new LedgerLine(cash.getId(), 500, 0, null),
                                new LedgerLine(liability.getId(), 0, 500, null)))
                .origin("desktop")
                .recordedAt(java.time.Instant.now().minus(10, java.time.temporal.ChronoUnit.DAYS)));
        ledger.post(EntryRequest.of(enums.TransactionType.COLLECTION,
                        List.of(new LedgerLine(cash.getId(), 300, 0, null),
                                new LedgerLine(liability.getId(), 0, 300, null)))
                .origin("desktop"));

        AccountLedgerResult all = reports.accountLedger(cash, null, null);
        assertEquals(0, all.getOpeningBalance());
        assertEquals(800, all.getClosingBalance());
        assertEquals(800, all.getTotalDebits());
        assertEquals(2, all.getRows().size());
        assertEquals(800, all.getRows().get(1).getRunningBalance());

        AccountLedgerResult recent = reports.accountLedger(cash, LocalDate.now().minusDays(1), null);
        assertEquals(500, recent.getOpeningBalance());
        assertEquals(800, recent.getClosingBalance());
        assertEquals(1, recent.getRows().size());
    }

    @Test
    void dashboardChartQueriesReturnUsableSeries() throws Exception {
        assertTrue(reports.dailyCollections(30) != null);
        assertTrue(reports.loanStatusCounts() != null);
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
