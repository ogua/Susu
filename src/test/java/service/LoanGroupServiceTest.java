package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.UUID;
import models.Customer;
import models.GroupLoan;
import models.LoanGroup;
import models.LoanGroupMember;
import models.LoanProduct;
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
    private final LoanProductService loanProducts = new LoanProductService();
    private final GroupLoanService groupLoans = new GroupLoanService();
    private static final String AGENT_ID = "agent-1";
    private static final String MANAGER_ID = "manager-1";

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
    void removesAMemberWithNoActiveGroupLoan() throws Exception {
        LoanGroup group = loanGroups.create("Market Traders", "LGRP-003", "setup");
        Customer customer = newCustomer("Efua", "Boateng");
        LoanGroupMember member = loanGroups.addMember(group.getId(), customer.getId());

        LoanGroupMember removed = loanGroups.removeMember(member.getId());

        assertEquals("left", removed.getStatus());
        assertNotNull(removed.getLeftAt());
    }

    @Test
    void blocksRemovingAMemberJointlyLiableOnADisbursedUnclosedGroupLoan() throws Exception {
        LoanGroup group = loanGroups.create("Market Traders", "LGRP-004", "setup");
        LoanGroupMember memberOne = loanGroups.addMember(group.getId(), newCustomer("Yaw", "Asante").getId());
        loanGroups.addMember(group.getId(), newCustomer("Abena", "Owusu").getId());

        LoanProduct product = loanProducts.getOrCreateDefault();
        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 1000_00, null, null);
        groupLoans.approve(applied.getId(), MANAGER_ID);
        groupLoans.disburse(applied.getId(), MANAGER_ID);

        assertThrows(IllegalStateException.class, () -> loanGroups.removeMember(memberOne.getId()));
    }
}
