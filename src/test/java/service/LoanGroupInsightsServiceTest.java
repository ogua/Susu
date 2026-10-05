package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.LoanFrequency;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.LocalDate;
import java.util.List;
import java.util.UUID;
import models.Customer;
import models.GroupLoan;
import models.LoanGroup;
import models.SavingsAccount;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

/** Group overview, history and collection sheet — mirrors tests/Feature/LoanGroups/CustomerGroupFeaturesTest.php. */
class LoanGroupInsightsServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final LoanGroupService loanGroups = new LoanGroupService();
    private final GroupLoanService groupLoans = new GroupLoanService();
    private final SavingsAccountService accounts = new SavingsAccountService();
    private final SavingsProductService products = new SavingsProductService();
    private final LoanGroupInsightsService insights = new LoanGroupInsightsService();
    private static final String AGENT_ID = "agent-1";

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-group-insights-test-");
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
        customer.setLastName("Member");
        customer.setPhone("+233" + UUID.randomUUID().toString().substring(0, 8));
        return customers.register(customer, "setup", null);
    }

    /** Issues 1,000 GHS (weekly 100 GHS, first payment today) and activates it. */
    private GroupLoan activeLoan(LoanGroup group, Customer customer, SavingsAccount account) throws Exception {
        GroupLoan loan = groupLoans.issue(AGENT_ID, group.getId(), customer.getId(), 1000_00, 50_00, 100_00,
                LoanFrequency.WEEKLY, LocalDate.now(), null, null);
        groupLoans.recordDeposit(loan.getId(), account.getId(), 50_00, AGENT_ID, null, null);
        return groupLoans.activate(loan.getId(), AGENT_ID);
    }

    @Test
    void issuesLoansAndOpensSavingsForTheWholeGroup() throws Exception {
        LoanGroup group = loanGroups.create("Bulk Group", "LGRP-BLK", "setup");
        Customer yaw = newCustomer("Yaw");
        Customer efua = newCustomer("Efua");
        loanGroups.addMember(group.getId(), yaw.getId());
        loanGroups.addMember(group.getId(), efua.getId());
        String productId = products.getOrCreateDefault().getId();
        accounts.open(yaw.getId(), productId, AGENT_ID, null);

        LoanGroupInsightsService.BulkResult savings = insights.openSavingsForGroup(AGENT_ID, group.getId(), productId, null);
        assertEquals(1, savings.created().size());
        assertEquals(1, savings.skipped().size());

        LoanGroupInsightsService.BulkResult first = insights.issueLoansToGroup(AGENT_ID, group.getId(), 500_00, 0, 50_00,
                LoanFrequency.WEEKLY, LocalDate.now(), null);
        LoanGroupInsightsService.BulkResult second = insights.issueLoansToGroup(AGENT_ID, group.getId(), 500_00, 0, 50_00,
                LoanFrequency.WEEKLY, LocalDate.now(), null);
        assertEquals(2, first.created().size());
        assertTrue(second.created().isEmpty());
        assertEquals(2, second.skipped().size());
    }

    @Test
    void summarisesBuildsHistoryAndPostsASheet() throws Exception {
        LoanGroup group = loanGroups.create("Insights Group", "LGRP-INS", "setup");
        Customer ama = newCustomer("Ama");
        Customer kofi = newCustomer("Kofi");
        SavingsAccount amaAccount = accounts.open(ama.getId(), products.getOrCreateDefault().getId(), AGENT_ID, null);
        SavingsAccount kofiAccount = accounts.open(kofi.getId(), products.getOrCreateDefault().getId(), AGENT_ID, null);
        GroupLoan amaLoan = activeLoan(group, ama, amaAccount);
        activeLoan(group, kofi, kofiAccount);
        Customer saverOnly = newCustomer("Esi");
        loanGroups.addMember(group.getId(), saverOnly.getId());

        LoanGroupInsightsService.Summary summary = insights.summary(group.getId());
        assertEquals(3, summary.activeMembers());
        assertEquals(2, summary.activeLoans());
        assertEquals(2000_00, summary.totalDisbursed());
        assertEquals(2000_00, summary.outstanding());
        assertEquals(100_00, summary.depositsCollected());

        List<LoanGroupInsightsService.SheetRow> sheet = insights.sheet(group.getId(), LocalDate.now());
        assertEquals(3, sheet.size());
        LoanGroupInsightsService.SheetRow amaRow = sheet.stream().filter(row -> row.customerId().equals(ama.getId())).findFirst().orElseThrow();
        assertEquals(100_00, amaRow.amountDue());
        assertEquals(amaAccount.getId(), amaRow.savingsAccountId());
        LoanGroupInsightsService.SheetRow esiRow = sheet.stream().filter(row -> row.customerId().equals(saverOnly.getId())).findFirst().orElseThrow();
        assertNull(esiRow.groupLoanId());

        long contribution = amaRow.contributionAmount();
        // A deposit that isn't a multiple of the contribution stops the sheet before anything posts.
        assertThrows(IllegalArgumentException.class, () -> insights.post(AGENT_ID, "Agent",
                List.of(new LoanGroupInsightsService.SheetEntry(amaRow, 100_00, contribution + 1))));
        assertEquals(1000_00, groupLoans.findById(amaLoan.getId()).getOutstandingBalance());

        LoanGroupInsightsService.PostResult result = insights.post(AGENT_ID, "Agent",
                List.of(new LoanGroupInsightsService.SheetEntry(amaRow, 100_00, contribution * 2)));
        assertEquals(1, result.repaymentsCount());
        assertEquals(1, result.depositsCount());
        assertEquals(900_00, groupLoans.findById(amaLoan.getId()).getOutstandingBalance());
        assertEquals(100_00, insights.summary(group.getId()).totalPaid());

        List<String> history = insights.history(group.getId()).stream().map(LoanGroupInsightsService.HistoryEvent::description).toList();
        assertTrue(history.stream().anyMatch(description -> description.startsWith("Repayment on")));
        assertTrue(history.stream().anyMatch(description -> description.endsWith("joined the group")));
        assertTrue(history.stream().anyMatch(description -> description.endsWith("disbursed")));
    }
}
