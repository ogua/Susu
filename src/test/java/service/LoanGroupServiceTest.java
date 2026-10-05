package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.LoanFrequency;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.LocalDate;
import java.util.UUID;
import models.Customer;
import models.GroupLoan;
import models.LoanGroup;
import models.LoanGroupMember;
import models.SavingsAccount;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertThrows;

/**
 * Loan group roster: create, add/remove members, no rotation concept (unlike
 * susu groups). Mirrors the shape of tests/Feature/LoanGroups/LoanGroupLifecycleTest.php.
 */
class LoanGroupServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final LoanGroupService loanGroups = new LoanGroupService();
    private final GroupLoanService groupLoans = new GroupLoanService();
    private static final String AGENT_ID = "agent-1";

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-loan-group-test-");
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

    private GroupLoan activateLoanFor(LoanGroup group, Customer customer) throws Exception {
        GroupLoan loan = groupLoans.issue(AGENT_ID, group.getId(), customer.getId(),
                1000_00, 100_00, 100_00, LoanFrequency.WEEKLY, LocalDate.now(), null, null);
        SavingsAccount account = new SavingsAccountService().open(customer.getId(),
                new SavingsProductService().getOrCreateDefault().getId(), AGENT_ID, null);
        groupLoans.recordDeposit(loan.getId(), account.getId(), 100_00, AGENT_ID, null, null);
        return groupLoans.activate(loan.getId(), AGENT_ID);
    }

    @Test
    void addsMembersToALoanGroupWithNoRotationConcept() throws Exception {
        LoanGroup group = loanGroups.create("Market Traders", "LGRP-001", "setup");
        Customer customer = newCustomer("Ama", "Serwaa");

        LoanGroupMember member = loanGroups.addMember(group.getId(), customer.getId());

        assertEquals("active", member.getStatus());
        assertEquals(customer.getId(), member.getCustomerId());
    }

    @Test
    void refusesToAddTheSameCustomerTwiceWhileActive() throws Exception {
        LoanGroup group = loanGroups.create("Market Traders", "LGRP-002", "setup");
        Customer customer = newCustomer("Kofi", "Owusu");
        loanGroups.addMember(group.getId(), customer.getId());

        assertThrows(IllegalArgumentException.class, () -> loanGroups.addMember(group.getId(), customer.getId()));
    }

    @Test
    void removesAMemberWithNoActiveLoan() throws Exception {
        LoanGroup group = loanGroups.create("Market Traders", "LGRP-003", "setup");
        Customer customer = newCustomer("Efua", "Boateng");
        LoanGroupMember member = loanGroups.addMember(group.getId(), customer.getId());

        LoanGroupMember removed = loanGroups.removeMember(member.getId());

        assertEquals("left", removed.getStatus());
        assertNotNull(removed.getLeftAt());
    }

    @Test
    void blocksRemovingAMemberWithAnActiveLoan() throws Exception {
        LoanGroup group = loanGroups.create("Market Traders", "LGRP-004", "setup");
        Customer customer = newCustomer("Yaw", "Asante");
        GroupLoan loan = activateLoanFor(group, customer);

        assertThrows(IllegalStateException.class, () -> loanGroups.removeMember(loan.getLoanGroupMemberId()));
    }

    @Test
    void reportsGroupOutstandingAsTheSumOfActiveMemberLoans() throws Exception {
        LoanGroup group = loanGroups.create("Market Traders", "LGRP-005", "setup");
        activateLoanFor(group, newCustomer("Adjoa", "Mensah"));
        activateLoanFor(group, newCustomer("Kojo", "Danso"));

        assertEquals(2000_00, groupLoans.groupOutstanding(group.getId()));
    }
}
