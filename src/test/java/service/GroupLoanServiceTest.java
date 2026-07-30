package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.GroupLoanStatus;
import enums.InstallmentStatus;
import enums.InterestMethod;
import enums.LoanFrequency;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import java.util.UUID;
import models.Customer;
import models.GroupLoan;
import models.GroupLoanBorrower;
import models.GroupLoanInstallment;
import models.LoanGroup;
import models.LoanGroupMember;
import models.LoanProduct;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * End-to-end run of the standalone-mode group loan engine: apply, approve,
 * disburse (equal split with remainder to the last member), and repay under
 * joint & several liability. Mirrors the shape of
 * tests/Feature/GroupLoans/GroupLoanLifecycleTest.php.
 */
class GroupLoanServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final LoanGroupService loanGroups = new LoanGroupService();
    private final LoanProductService loanProducts = new LoanProductService();
    private final GroupLoanService groupLoans = new GroupLoanService();
    private final LedgerService ledger = new LedgerService();
    private static final String AGENT_ID = "agent-1";
    private static final String MANAGER_ID = "manager-1";

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-group-loan-test-");
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

    /** 3 members so a non-divisible principal exercises the rounding remainder. */
    private LoanGroup newLoanGroupWithThreeMembers(String code) throws Exception {
        LoanGroup group = loanGroups.create("Market Traders " + code, code, "setup");
        loanGroups.addMember(group.getId(), newCustomer("Member", "One").getId());
        loanGroups.addMember(group.getId(), newCustomer("Member", "Two").getId());
        loanGroups.addMember(group.getId(), newCustomer("Member", "Three").getId());
        return loanGroups.findById(group.getId());
    }

    @Test
    void disbursesAGroupLoanAndSplitsThePrincipalEvenlyRemainderToTheLastMember() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-101");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 1000_00, null, null);
        assertEquals(GroupLoanStatus.APPLIED, applied.getStatus());

        GroupLoan approved = groupLoans.approve(applied.getId(), MANAGER_ID);
        assertEquals(GroupLoanStatus.APPROVED, approved.getStatus());

        GroupLoan disbursed = groupLoans.disburse(approved.getId(), MANAGER_ID);
        assertEquals(GroupLoanStatus.DISBURSED, disbursed.getStatus());
        assertEquals(3, (int) disbursed.getMemberCountAtDisbursement());
        assertEquals(3, disbursed.getInstallments().size());

        // intdiv(100000, 3) = 33333, remainder 1 goes to the last member (joined_at order).
        List<GroupLoanBorrower> borrowers = disbursed.getBorrowers();
        assertEquals(3, borrowers.size());
        assertEquals(33_333, borrowers.get(0).getSharePrincipal());
        assertEquals(33_333, borrowers.get(1).getSharePrincipal());
        assertEquals(33_334, borrowers.get(2).getSharePrincipal());
        assertEquals(disbursed.getPrincipalAmount(), borrowers.stream().mapToLong(GroupLoanBorrower::getSharePrincipal).sum());

        long principalSum = disbursed.getInstallments().stream().mapToLong(GroupLoanInstallment::getPrincipalDue).sum();
        assertEquals(1000_00, principalSum);

        var receivable = findLedgerAccountById(disbursed.getReceivableAccountId());
        assertEquals(1000_00, ledger.recomputeBalance(receivable));
    }

    @Test
    void walksAGroupLoanThroughApplyToReject() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-102");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 500_00, null, null);
        GroupLoan rejected = groupLoans.reject(applied.getId(), MANAGER_ID, "Not enough history");

        assertEquals(GroupLoanStatus.REJECTED, rejected.getStatus());
        assertEquals("Not enough history", rejected.getRejectionReason());
    }

    @Test
    void refusesToApplyForAGroupLoanWithFewerThanTwoActiveMembers() throws Exception {
        LoanGroup group = loanGroups.create("Solo Group", "LGRP-103", "setup");
        loanGroups.addMember(group.getId(), newCustomer("Solo", "Member").getId());
        LoanProduct product = loanProducts.getOrCreateDefault();

        assertThrows(IllegalArgumentException.class,
                () -> groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 500_00, null, null));
    }

    @Test
    void refusesToDisburseAGroupLoanWhoseMembershipDroppedBelowTwoSinceApplication() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-104");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 500_00, null, null);
        groupLoans.approve(applied.getId(), MANAGER_ID);

        List<LoanGroupMember> members = loanGroups.findMembers(group.getId());
        loanGroups.removeMember(members.get(1).getId());
        loanGroups.removeMember(members.get(2).getId());

        assertThrows(IllegalStateException.class, () -> groupLoans.disburse(applied.getId(), MANAGER_ID));
    }

    @Test
    void appliesOneMembersRepaymentAgainstTheSharedScheduleAndOnlyThatBorrowersShareOutstandingMoves() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-105");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 900_00, null, null);
        groupLoans.approve(applied.getId(), MANAGER_ID);
        GroupLoan disbursed = groupLoans.disburse(applied.getId(), MANAGER_ID);

        GroupLoanBorrower payingBorrower = disbursed.getBorrowers().get(0);
        GroupLoanBorrower otherBorrower = disbursed.getBorrowers().get(1);

        // Small, partial amount well within the paying member's own share (30000).
        long repaymentAmount = 10_000;
        GroupLoanRepaymentResult result = groupLoans.recordRepayment(payingBorrower.getId(), repaymentAmount, MANAGER_ID, null, null);

        assertFalse(result.duplicate());
        assertEquals(disbursed.getOutstandingBalance() - repaymentAmount, result.groupLoan().getOutstandingBalance());
        assertEquals(payingBorrower.getSharePrincipal() - repaymentAmount, result.borrower().getShareOutstanding());

        GroupLoanBorrower refreshedOther = groupLoans.findBorrowerById(otherBorrower.getId());
        assertEquals(otherBorrower.getSharePrincipal(), refreshedOther.getShareOutstanding());
    }

    @Test
    void floorsAnOverpayingMembersShareOutstandingAtZeroWithoutReallocatingTheExcess() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-106");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 900_00, null, null);
        groupLoans.approve(applied.getId(), MANAGER_ID);
        GroupLoan disbursed = groupLoans.disburse(applied.getId(), MANAGER_ID);

        GroupLoanBorrower payingBorrower = disbursed.getBorrowers().get(0);
        GroupLoanBorrower otherBorrower = disbursed.getBorrowers().get(1);

        long overpayment = payingBorrower.getSharePrincipal() + 50_00;
        GroupLoanRepaymentResult result = groupLoans.recordRepayment(payingBorrower.getId(), overpayment, MANAGER_ID, null, null);

        assertEquals(0, result.borrower().getShareOutstanding());
        GroupLoanBorrower refreshedOther = groupLoans.findBorrowerById(otherBorrower.getId());
        assertEquals(otherBorrower.getSharePrincipal(), refreshedOther.getShareOutstanding());
        assertEquals(disbursed.getOutstandingBalance() - overpayment, result.groupLoan().getOutstandingBalance());
    }

    @Test
    void closesTheGroupLoanOnceTheSharedOutstandingBalanceIsFullyRepaid() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-107");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 900_00, null, null);
        groupLoans.approve(applied.getId(), MANAGER_ID);
        GroupLoan disbursed = groupLoans.disburse(applied.getId(), MANAGER_ID);

        GroupLoanBorrower borrower = disbursed.getBorrowers().get(0);
        GroupLoanRepaymentResult result = groupLoans.recordRepayment(
                borrower.getId(), disbursed.getOutstandingBalance(), MANAGER_ID, null, null);

        assertEquals(GroupLoanStatus.CLOSED, result.groupLoan().getStatus());
        assertEquals(0, result.groupLoan().getOutstandingBalance());

        for (GroupLoanInstallment installment : groupLoans.findInstallments(disbursed.getId())) {
            assertEquals(InstallmentStatus.PAID, installment.getStatus());
        }

        var receivable = findLedgerAccountById(disbursed.getReceivableAccountId());
        assertEquals(0, ledger.recomputeBalance(receivable));
    }

    @Test
    void isIdempotentWhenTheSameClientReferenceIsReplayed() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-108");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 900_00, null, null);
        groupLoans.approve(applied.getId(), MANAGER_ID);
        GroupLoan disbursed = groupLoans.disburse(applied.getId(), MANAGER_ID);
        GroupLoanBorrower borrower = disbursed.getBorrowers().get(0);

        String ref = UUID.randomUUID().toString();
        GroupLoanRepaymentResult first = groupLoans.recordRepayment(borrower.getId(), 100_00, MANAGER_ID, ref, null);
        GroupLoanRepaymentResult second = groupLoans.recordRepayment(borrower.getId(), 100_00, MANAGER_ID, ref, null);

        assertTrue(second.duplicate());
        assertEquals(first.entry().getId(), second.entry().getId());
        assertEquals(disbursed.getOutstandingBalance() - 100_00, groupLoans.findById(disbursed.getId()).getOutstandingBalance());
    }

    @Test
    void rejectsARepaymentLargerThanTheSharedOutstandingBalance() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-109");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 900_00, null, null);
        groupLoans.approve(applied.getId(), MANAGER_ID);
        GroupLoan disbursed = groupLoans.disburse(applied.getId(), MANAGER_ID);
        GroupLoanBorrower borrower = disbursed.getBorrowers().get(0);

        assertThrows(IllegalArgumentException.class,
                () -> groupLoans.recordRepayment(borrower.getId(), disbursed.getOutstandingBalance() + 1, MANAGER_ID, null, null));
    }

    @Test
    void restructuresADisbursedGroupLoanOntoANewProductReSplittingTheRolledOverPrincipal() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-201");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 900_00, null, null);
        groupLoans.approve(applied.getId(), MANAGER_ID);
        GroupLoan disbursed = groupLoans.disburse(applied.getId(), MANAGER_ID);
        long principalOutstanding = findLedgerAccountById(disbursed.getReceivableAccountId()).getBalance();

        LoanProduct newProduct = new LoanProduct();
        newProduct.setName("Restructure Product");
        newProduct.setCode("GL-RESTR-" + UUID.randomUUID().toString().substring(0, 6));
        newProduct.setInterestMethod(InterestMethod.FLAT);
        newProduct.setInterestRateBps(500);
        newProduct.setTermPeriodCount(6);
        newProduct.setRepaymentFrequency(LoanFrequency.MONTHLY);
        newProduct.setMinAmount(1);
        newProduct.setMaxAmount(1_000_000_00);
        newProduct = loanProducts.create(newProduct);

        GroupLoan newGroupLoan = groupLoans.restructure(disbursed.getId(), MANAGER_ID, newProduct.getId(), "Group struggling with the old schedule");

        assertEquals(GroupLoanStatus.DISBURSED, newGroupLoan.getStatus());
        assertEquals(disbursed.getId(), newGroupLoan.getPreviousGroupLoanId());
        assertEquals(principalOutstanding, newGroupLoan.getRolledOverAmount());
        assertEquals(principalOutstanding, newGroupLoan.getPrincipalAmount());
        assertEquals(3, (int) newGroupLoan.getMemberCountAtDisbursement());
        assertEquals(6, newGroupLoan.getInstallments().size());

        List<GroupLoanBorrower> newBorrowers = newGroupLoan.getBorrowers();
        assertEquals(3, newBorrowers.size());
        assertEquals(newGroupLoan.getPrincipalAmount(), newBorrowers.stream().mapToLong(GroupLoanBorrower::getSharePrincipal).sum());

        GroupLoan oldGroupLoan = groupLoans.findById(disbursed.getId());
        assertEquals(GroupLoanStatus.REFINANCED, oldGroupLoan.getStatus());
        assertEquals(0, oldGroupLoan.getOutstandingBalance());
        assertEquals("restructure", oldGroupLoan.getRefinanceType());
        assertEquals(0, findLedgerAccountById(oldGroupLoan.getReceivableAccountId()).getBalance());
        assertEquals(3, groupLoans.findBorrowers(disbursed.getId()).size()); // old borrowers untouched
    }

    @Test
    void refusesToRestructureAGroupLoanThatIsNotDisbursed() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-202");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 500_00, null, null);

        assertThrows(IllegalStateException.class, () -> groupLoans.restructure(applied.getId(), MANAGER_ID, product.getId(), "no"));
    }

    @Test
    void refusesToRestructureAGroupLoanWhoseMembershipDroppedBelowTwo() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-203");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 500_00, null, null);
        groupLoans.approve(applied.getId(), MANAGER_ID);
        GroupLoan disbursed = groupLoans.disburse(applied.getId(), MANAGER_ID);

        // Direct update, bypassing LoanGroupService.removeMember()'s own guard
        // against removing a member still jointly liable on this very
        // disbursed loan — simulates membership drift after disbursement,
        // mirroring the backend test's direct ->update(['status' => 'left']).
        List<LoanGroupMember> members = loanGroups.findMembers(group.getId());
        markMembersLeft(members.get(1).getId(), members.get(2).getId());

        assertThrows(IllegalStateException.class, () -> groupLoans.restructure(disbursed.getId(), MANAGER_ID, product.getId(), "no"));
    }

    @Test
    void topsUpADisbursedGroupLoanWithFreshCashReSplittingTheNewPrincipal() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-204");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 900_00, null, null);
        groupLoans.approve(applied.getId(), MANAGER_ID);
        GroupLoan disbursed = groupLoans.disburse(applied.getId(), MANAGER_ID);
        long principalOutstanding = findLedgerAccountById(disbursed.getReceivableAccountId()).getBalance();

        GroupLoan newGroupLoan = groupLoans.topUp(disbursed.getId(), MANAGER_ID, 300_00, "Group requested more capital");

        assertEquals(GroupLoanStatus.DISBURSED, newGroupLoan.getStatus());
        assertEquals(disbursed.getId(), newGroupLoan.getPreviousGroupLoanId());
        assertEquals(principalOutstanding, newGroupLoan.getRolledOverAmount());
        assertEquals(principalOutstanding + 300_00, newGroupLoan.getPrincipalAmount());

        List<GroupLoanBorrower> newBorrowers = newGroupLoan.getBorrowers();
        assertEquals(3, newBorrowers.size());
        assertEquals(newGroupLoan.getPrincipalAmount(), newBorrowers.stream().mapToLong(GroupLoanBorrower::getSharePrincipal).sum());

        GroupLoan oldGroupLoan = groupLoans.findById(disbursed.getId());
        assertEquals(GroupLoanStatus.REFINANCED, oldGroupLoan.getStatus());
        assertEquals("top_up", oldGroupLoan.getRefinanceType());
        assertEquals(0, findLedgerAccountById(oldGroupLoan.getReceivableAccountId()).getBalance());
    }

    @Test
    void refusesToTopUpAGroupLoanThatIsNotDisbursed() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-205");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 500_00, null, null);

        assertThrows(IllegalStateException.class, () -> groupLoans.topUp(applied.getId(), MANAGER_ID, 100_00, "no"));
    }

    @Test
    void refusesToTopUpAGroupLoanWhoseMembershipDroppedBelowTwo() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-206");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 500_00, null, null);
        groupLoans.approve(applied.getId(), MANAGER_ID);
        GroupLoan disbursed = groupLoans.disburse(applied.getId(), MANAGER_ID);

        List<LoanGroupMember> members = loanGroups.findMembers(group.getId());
        markMembersLeft(members.get(1).getId(), members.get(2).getId());

        assertThrows(IllegalStateException.class, () -> groupLoans.topUp(disbursed.getId(), MANAGER_ID, 100_00, "no"));
    }

    @Test
    void writesOffADisbursedGroupLoanZeroingTheSharedReceivableWithoutTouchingAnyBorrowerShare() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-207");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 900_00, null, null);
        groupLoans.approve(applied.getId(), MANAGER_ID);
        GroupLoan disbursed = groupLoans.disburse(applied.getId(), MANAGER_ID);
        long outstandingBeforeWriteOff = disbursed.getOutstandingBalance();
        long principalOutstanding = findLedgerAccountById(disbursed.getReceivableAccountId()).getBalance();
        List<GroupLoanBorrower> borrowersBefore = disbursed.getBorrowers();

        GroupLoan writtenOff = groupLoans.writeOff(disbursed.getId(), MANAGER_ID, "Group disbanded");

        assertEquals(GroupLoanStatus.WRITTEN_OFF, writtenOff.getStatus());
        assertEquals(0, writtenOff.getOutstandingBalance());
        assertEquals(outstandingBeforeWriteOff, writtenOff.getWriteOffAmount());
        assertEquals("Group disbanded", writtenOff.getWriteOffReason());
        assertNotNull(writtenOff.getWrittenOffAt());
        assertEquals(0, findLedgerAccountById(writtenOff.getReceivableAccountId()).getBalance());

        var badDebtExpense = findLedgerAccountByCode("5100-BADDEBT");
        assertEquals(principalOutstanding, badDebtExpense.getBalance());

        List<GroupLoanBorrower> borrowersAfter = groupLoans.findBorrowers(disbursed.getId());
        for (int i = 0; i < borrowersBefore.size(); i++) {
            assertEquals(borrowersBefore.get(i).getShareOutstanding(), borrowersAfter.get(i).getShareOutstanding());
        }
    }

    @Test
    void refusesToWriteOffAGroupLoanThatIsNotDisbursed() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-208");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 500_00, null, null);

        assertThrows(IllegalStateException.class, () -> groupLoans.writeOff(applied.getId(), MANAGER_ID, "no"));
    }

    @Test
    void refusesToWriteOffAGroupLoanWithNoOutstandingBalance() throws Exception {
        LoanGroup group = newLoanGroupWithThreeMembers("LGRP-209");
        LoanProduct product = loanProducts.getOrCreateDefault();

        GroupLoan applied = groupLoans.apply(AGENT_ID, group.getId(), product.getId(), 900_00, null, null);
        groupLoans.approve(applied.getId(), MANAGER_ID);
        GroupLoan disbursed = groupLoans.disburse(applied.getId(), MANAGER_ID);
        GroupLoanBorrower borrower = disbursed.getBorrowers().get(0);
        groupLoans.recordRepayment(borrower.getId(), disbursed.getOutstandingBalance(), MANAGER_ID, null, null);

        assertThrows(IllegalStateException.class, () -> groupLoans.writeOff(disbursed.getId(), MANAGER_ID, "no"));
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

    /**
     * Bypasses LoanGroupService.removeMember()'s guard against removing a
     * member still jointly liable on a disbursed group loan — used only to
     * simulate membership drift after disbursement in tests, mirroring the
     * backend test's direct model update.
     */
    private void markMembersLeft(String... memberIds) throws Exception {
        try (var conn = DatabaseConnection.getConnection()) {
            for (String memberId : memberIds) {
                try (var ps = conn.prepareStatement(
                        "UPDATE loan_group_members SET status = 'left', left_at = ?, updated_at = ? WHERE id = ?")) {
                    String now = java.time.Instant.now().toString();
                    ps.setString(1, now);
                    ps.setString(2, now);
                    ps.setString(3, memberId);
                    ps.executeUpdate();
                }
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
