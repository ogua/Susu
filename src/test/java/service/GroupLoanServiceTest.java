package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.DepositStatus;
import enums.GroupLoanStatus;
import enums.InstallmentStatus;
import enums.LoanFrequency;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.LocalDate;
import java.util.List;
import java.util.UUID;
import models.Customer;
import models.GroupLoan;
import models.GroupLoanInstallment;
import models.LoanGroup;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * End-to-end run of the standalone-mode group loan engine: issue a per-member
 * loan, record its security deposit, activate it (schedule spread from the
 * periodic amount), repay it, apply the deposit against the balance, and
 * write it off. Mirrors tests/Feature/GroupLoans/GroupLoanLifecycleTest.php.
 */
class GroupLoanServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final LoanGroupService loanGroups = new LoanGroupService();
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

    private LoanGroup newLoanGroup(String code) throws Exception {
        return loanGroups.create("Market Traders " + code, code, "setup");
    }

    private GroupLoan issue(LoanGroup group, Customer customer, long principal, long deposit, long periodic)
            throws Exception {
        return groupLoans.issue(AGENT_ID, group.getId(), customer.getId(), principal, deposit, periodic,
                LoanFrequency.WEEKLY, LocalDate.now(), null, null);
    }

    private GroupLoan activate(GroupLoan loan) throws Exception {
        groupLoans.recordDeposit(loan.getId(), loan.getSecurityDepositAmount(), AGENT_ID, null, null);
        return groupLoans.activate(loan.getId(), AGENT_ID);
    }

    @Test
    void issuesADraftLoanWithoutTouchingTheLedger() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-101");
        GroupLoan loan = issue(group, newCustomer("Ama", "Serwaa"), 1000_00, 100_00, 100_00);

        assertEquals(GroupLoanStatus.DRAFT, loan.getStatus());
        assertEquals(DepositStatus.PENDING, loan.getDepositStatus());
        assertEquals(0, loan.getOutstandingBalance());
        assertEquals(10, loan.getTotalPeriods());
        assertTrue(groupLoans.findInstallments(loan.getId()).isEmpty());
    }

    @Test
    void recordsTheDepositAsAHeldLiability() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-102");
        GroupLoan loan = issue(group, newCustomer("Kofi", "Owusu"), 1000_00, 100_00, 100_00);

        GroupLoan afterDeposit = groupLoans.recordDeposit(loan.getId(), 100_00, AGENT_ID, null, null);

        assertEquals(DepositStatus.HELD, afterDeposit.getDepositStatus());
        var depositAccount = findLedgerAccountByCode("GLDEP-" + loan.getLoanNumber());
        assertEquals(100_00, ledger.recomputeBalance(depositAccount));
    }

    @Test
    void willNotActivateBeforeTheDepositIsHeld() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-103");
        GroupLoan loan = issue(group, newCustomer("Efua", "Boateng"), 1000_00, 100_00, 100_00);

        assertThrows(IllegalStateException.class, () -> groupLoans.activate(loan.getId(), AGENT_ID));
    }

    @Test
    void activatesGeneratesTheScheduleAndDisbursesThePrincipal() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-104");
        GroupLoan active = activate(issue(group, newCustomer("Yaw", "Asante"), 1000_00, 100_00, 100_00));

        assertEquals(GroupLoanStatus.ACTIVE, active.getStatus());
        assertEquals(1000_00, active.getOutstandingBalance());

        List<GroupLoanInstallment> installments = groupLoans.findInstallments(active.getId());
        assertEquals(10, installments.size());
        assertEquals(1000_00, installments.stream().mapToLong(GroupLoanInstallment::getAmountDue).sum());

        var receivable = findLedgerAccountByCode("GLN-" + active.getLoanNumber());
        assertEquals(1000_00, ledger.recomputeBalance(receivable));
    }

    @Test
    void appliesARepaymentOldestFirstAndReducesTheOutstandingBalance() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-105");
        GroupLoan active = activate(issue(group, newCustomer("Adjoa", "Mensah"), 1000_00, 100_00, 100_00));

        var result = groupLoans.recordRepayment(active.getId(), 250_00, AGENT_ID, null, null);
        assertEquals(750_00, result.groupLoan().getOutstandingBalance());

        List<GroupLoanInstallment> installments = groupLoans.findInstallments(active.getId());
        assertEquals(InstallmentStatus.PAID, installments.get(0).getStatus());
        assertEquals(InstallmentStatus.PAID, installments.get(1).getStatus());
        assertEquals(InstallmentStatus.PARTIALLY_PAID, installments.get(2).getStatus());
        assertEquals(50_00, installments.get(2).getAmountPaid());
    }

    @Test
    void autoClosesAndRefundsTheStillHeldDepositWhenFullyRepaid() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-106");
        GroupLoan active = activate(issue(group, newCustomer("Kojo", "Danso"), 1000_00, 100_00, 100_00));

        var result = groupLoans.recordRepayment(active.getId(), 1000_00, AGENT_ID, null, null);

        GroupLoan closed = groupLoans.findById(active.getId());
        assertEquals(GroupLoanStatus.CLOSED, result.groupLoan().getStatus());
        assertEquals(DepositStatus.SETTLED, closed.getDepositStatus());

        var depositAccount = findLedgerAccountByCode("GLDEP-" + active.getLoanNumber());
        assertEquals(0, ledger.recomputeBalance(depositAccount));
    }

    @Test
    void rejectsARepaymentLargerThanTheOutstandingBalance() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-107");
        GroupLoan active = activate(issue(group, newCustomer("Abena", "Owusu"), 1000_00, 100_00, 100_00));

        assertThrows(IllegalArgumentException.class,
                () -> groupLoans.recordRepayment(active.getId(), 1000_01, AGENT_ID, null, null));
    }

    @Test
    void isIdempotentOnClientReferenceForDepositsAndRepayments() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-108");
        GroupLoan loan = issue(group, newCustomer("Yaa", "Nkrumah"), 1000_00, 100_00, 100_00);

        String depositRef = UUID.randomUUID().toString();
        groupLoans.recordDeposit(loan.getId(), 100_00, AGENT_ID, depositRef, null);
        groupLoans.recordDeposit(loan.getId(), 100_00, AGENT_ID, depositRef, null);
        GroupLoan active = groupLoans.activate(loan.getId(), AGENT_ID);

        String repayRef = UUID.randomUUID().toString();
        groupLoans.recordRepayment(active.getId(), 200_00, AGENT_ID, repayRef, null);
        var dup = groupLoans.recordRepayment(active.getId(), 200_00, AGENT_ID, repayRef, null);

        assertTrue(dup.duplicate());
        assertEquals(800_00, groupLoans.findById(active.getId()).getOutstandingBalance());
    }

    @Test
    void appliesAHeldDepositAgainstTheBalanceWithNoCashMovement() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-109");
        GroupLoan active = activate(issue(group, newCustomer("Kwesi", "Appiah"), 1000_00, 100_00, 100_00));
        groupLoans.recordRepayment(active.getId(), 200_00, AGENT_ID, null, null);

        long cashBefore = findLedgerAccountByCode("CASH-MAIN").getBalance();
        GroupLoan afterApply = groupLoans.applyDeposit(active.getId(), AGENT_ID, null);

        assertEquals(700_00, afterApply.getOutstandingBalance());
        assertEquals(DepositStatus.SETTLED, afterApply.getDepositStatus());
        assertEquals(cashBefore, findLedgerAccountByCode("CASH-MAIN").getBalance());
    }

    @Test
    void refundsTheExcessAndClosesTheLoanWhenTheDepositExceedsTheBalance() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-110");
        GroupLoan active = activate(issue(group, newCustomer("Esi", "Quaye"), 1000_00, 300_00, 100_00));
        groupLoans.recordRepayment(active.getId(), 900_00, AGENT_ID, null, null);

        long cashBefore = findLedgerAccountByCode("CASH-MAIN").getBalance();
        GroupLoan result = groupLoans.applyDeposit(active.getId(), AGENT_ID, null);

        assertEquals(GroupLoanStatus.CLOSED, result.getStatus());
        assertEquals(0, result.getOutstandingBalance());
        // the 200_00 excess is refunded to the member in cash
        assertEquals(cashBefore - 200_00, findLedgerAccountByCode("CASH-MAIN").getBalance());
    }

    @Test
    void writesOffAnActiveLoanSeizingTheDepositThenBadDebtingTheResidual() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-111");
        GroupLoan active = activate(issue(group, newCustomer("Nii", "Tetteh"), 1000_00, 100_00, 100_00));
        groupLoans.recordRepayment(active.getId(), 400_00, AGENT_ID, null, null);

        long badDebtBefore = findLedgerAccountByCode("5100-BADDEBT").getBalance();
        GroupLoan writtenOff = groupLoans.writeOff(active.getId(), MANAGER_ID, "Absconded");

        assertEquals(GroupLoanStatus.WRITTEN_OFF, writtenOff.getStatus());
        assertEquals(0, writtenOff.getOutstandingBalance());
        assertEquals(600_00, (long) writtenOff.getWriteOffAmount());
        assertEquals(DepositStatus.SETTLED, writtenOff.getDepositStatus());
        assertEquals(0, findLedgerAccountByCode("GLN-" + active.getLoanNumber()).getBalance());
        assertEquals(badDebtBefore + 500_00, findLedgerAccountByCode("5100-BADDEBT").getBalance());
    }

    @Test
    void refusesToWriteOffANonActiveLoan() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-112");
        GroupLoan draft = issue(group, newCustomer("Dela", "Agbo"), 1000_00, 100_00, 100_00);

        assertThrows(IllegalStateException.class, () -> groupLoans.writeOff(draft.getId(), MANAGER_ID, "no"));
    }

    @Test
    void allowsOnlyOneActiveLoanPerMemberAtATime() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-113");
        Customer customer = newCustomer("Selorm", "Mawuli");
        GroupLoan active = activate(issue(group, customer, 1000_00, 100_00, 100_00));

        assertThrows(IllegalStateException.class, () -> issue(group, customer, 500_00, 50_00, 50_00));

        groupLoans.recordRepayment(active.getId(), 1000_00, AGENT_ID, null, null);
        GroupLoan second = issue(group, customer, 500_00, 50_00, 50_00);
        assertEquals(GroupLoanStatus.DRAFT, second.getStatus());
    }

    @Test
    void usesTheClientReferenceAsTheGroupLoanId() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-114");
        String ref = UUID.randomUUID().toString();

        GroupLoan loan = groupLoans.issue(AGENT_ID, group.getId(), newCustomer("Afia", "Pokua").getId(),
                1000_00, 100_00, 100_00, LoanFrequency.WEEKLY, LocalDate.now(), null, ref);

        assertEquals(ref, loan.getId());
        assertNotNull(groupLoans.findById(ref));
    }

    private models.LedgerAccount findLedgerAccountByCode(String code) throws Exception {
        try (var conn = DatabaseConnection.getConnection();
             var ps = conn.prepareStatement("SELECT * FROM ledger_accounts WHERE code = ?")) {
            ps.setString(1, code);
            try (var rs = ps.executeQuery()) {
                models.LedgerAccount account = new models.LedgerAccount();
                if (!rs.next()) {
                    account.setCode(code);
                    account.setType(enums.LedgerAccountType.ASSET);
                    account.setBalance(0);
                    return account;
                }
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
