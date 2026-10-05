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
import models.SavingsAccount;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * End-to-end run of the standalone-mode group loan engine: issue a per-member
 * loan, record its security deposit into the member's savings account,
 * activate it (schedule spread from the periodic amount), repay it, and write
 * it off (optionally drawing savings down first). Mirrors
 * tests/Feature/GroupLoans/GroupLoanLifecycleTest.php.
 */
class GroupLoanServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final LoanGroupService loanGroups = new LoanGroupService();
    private final GroupLoanService groupLoans = new GroupLoanService();
    private final SavingsAccountService accounts = new SavingsAccountService();
    private final SavingsProductService products = new SavingsProductService();
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

    /** The customer's first savings account, opened on demand. */
    private SavingsAccount accountFor(String customerId) throws Exception {
        List<SavingsAccount> existing = accounts.findByCustomer(customerId);
        return existing.isEmpty()
                ? accounts.open(customerId, products.getOrCreateDefault().getId(), AGENT_ID, null)
                : existing.get(0);
    }

    private GroupLoan activate(GroupLoan loan) throws Exception {
        groupLoans.recordDeposit(loan.getId(), accountFor(loan.getCustomerId()).getId(),
                loan.getSecurityDepositAmount(), AGENT_ID, null, null);
        return groupLoans.activate(loan.getId(), AGENT_ID);
    }

    private long savingsLedgerBalance(SavingsAccount account) throws Exception {
        return ledger.recomputeBalance(findLedgerAccountByCode("SAV-" + account.getAccountNumber()));
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
    void recordsTheDepositIntoTheChosenSavingsAccount() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-102");
        GroupLoan loan = issue(group, newCustomer("Kofi", "Owusu"), 1000_00, 100_00, 100_00);
        SavingsAccount account = accountFor(loan.getCustomerId());

        GroupLoan afterDeposit = groupLoans.recordDeposit(loan.getId(), account.getId(), 100_00, AGENT_ID, null, null);

        assertEquals(DepositStatus.HELD, afterDeposit.getDepositStatus());
        assertEquals(100_00, accounts.findById(account.getId()).getBalance());
        assertEquals(100_00, savingsLedgerBalance(account));
    }

    @Test
    void rejectsADepositIntoAnotherCustomersSavingsAccount() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-115");
        GroupLoan loan = issue(group, newCustomer("Akua", "Frimpong"), 1000_00, 100_00, 100_00);
        SavingsAccount someoneElses = accountFor(newCustomer("Kweku", "Ansah").getId());

        assertThrows(IllegalArgumentException.class,
                () -> groupLoans.recordDeposit(loan.getId(), someoneElses.getId(), 100_00, AGENT_ID, null, null));
        assertEquals(DepositStatus.PENDING, groupLoans.findById(loan.getId()).getDepositStatus());
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
    void autoClosesWithoutTouchingSavingsWhenFullyRepaid() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-106");
        GroupLoan active = activate(issue(group, newCustomer("Kojo", "Danso"), 1000_00, 100_00, 100_00));
        SavingsAccount account = accountFor(active.getCustomerId());
        long cashBefore = findLedgerAccountByCode("CASH-MAIN").getBalance();

        var result = groupLoans.recordRepayment(active.getId(), 1000_00, AGENT_ID, null, null);

        GroupLoan closed = groupLoans.findById(active.getId());
        assertEquals(GroupLoanStatus.CLOSED, result.groupLoan().getStatus());
        assertEquals(DepositStatus.HELD, closed.getDepositStatus());
        // The deposit stays in savings; only the repayment itself moved cash.
        assertEquals(100_00, accounts.findById(account.getId()).getBalance());
        assertEquals(cashBefore + 1000_00, findLedgerAccountByCode("CASH-MAIN").getBalance());
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

        SavingsAccount account = accountFor(loan.getCustomerId());
        String depositRef = UUID.randomUUID().toString();
        groupLoans.recordDeposit(loan.getId(), account.getId(), 100_00, AGENT_ID, depositRef, null);
        groupLoans.recordDeposit(loan.getId(), account.getId(), 100_00, AGENT_ID, depositRef, null);
        assertEquals(100_00, accounts.findById(account.getId()).getBalance());
        GroupLoan active = groupLoans.activate(loan.getId(), AGENT_ID);

        String repayRef = UUID.randomUUID().toString();
        groupLoans.recordRepayment(active.getId(), 200_00, AGENT_ID, repayRef, null);
        var dup = groupLoans.recordRepayment(active.getId(), 200_00, AGENT_ID, repayRef, null);

        assertTrue(dup.duplicate());
        assertEquals(800_00, groupLoans.findById(active.getId()).getOutstandingBalance());
    }

    @Test
    void writeOffAppliesChosenSavingsThenBadDebtsOnlyTheResidual() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-111");
        GroupLoan active = activate(issue(group, newCustomer("Nii", "Tetteh"), 1000_00, 100_00, 100_00));
        groupLoans.recordRepayment(active.getId(), 400_00, AGENT_ID, null, null);
        // The 100_00 deposit already sits in this account.
        SavingsAccount account = accountFor(active.getCustomerId());

        long badDebtBefore = findLedgerAccountByCode("5100-BADDEBT").getBalance();
        GroupLoan writtenOff = groupLoans.writeOff(active.getId(), MANAGER_ID, "Absconded", account.getId(), 100_00);

        assertEquals(GroupLoanStatus.WRITTEN_OFF, writtenOff.getStatus());
        assertEquals(0, writtenOff.getOutstandingBalance());
        assertEquals(600_00, (long) writtenOff.getWriteOffAmount());
        assertEquals(account.getId(), writtenOff.getWriteOffSavingsAccountId());
        assertEquals(100_00, (long) writtenOff.getWriteOffSavingsApplied());
        assertEquals(DepositStatus.HELD, writtenOff.getDepositStatus());
        assertEquals(0, findLedgerAccountByCode("GLN-" + active.getLoanNumber()).getBalance());
        assertEquals(badDebtBefore + 500_00, findLedgerAccountByCode("5100-BADDEBT").getBalance());
        assertEquals(0, accounts.findById(account.getId()).getBalance());
        assertEquals(0, savingsLedgerBalance(account));
    }

    @Test
    void writeOffWithNoSavingsBadDebtsTheWholeBalanceAndLeavesSavingsAlone() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-109");
        GroupLoan active = activate(issue(group, newCustomer("Kwesi", "Appiah"), 1000_00, 100_00, 100_00));
        groupLoans.recordRepayment(active.getId(), 400_00, AGENT_ID, null, null);
        SavingsAccount account = accountFor(active.getCustomerId());

        long badDebtBefore = findLedgerAccountByCode("5100-BADDEBT").getBalance();
        GroupLoan writtenOff = groupLoans.writeOff(active.getId(), MANAGER_ID, "Absconded");

        assertEquals(0, (long) writtenOff.getWriteOffSavingsApplied());
        assertEquals(null, writtenOff.getWriteOffSavingsAccountId());
        assertEquals(badDebtBefore + 600_00, findLedgerAccountByCode("5100-BADDEBT").getBalance());
        assertEquals(100_00, accounts.findById(account.getId()).getBalance());
    }

    @Test
    void writeOffRefusesToApplyMoreThanTheSavingsBalance() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-110");
        GroupLoan active = activate(issue(group, newCustomer("Esi", "Quaye"), 1000_00, 100_00, 100_00));
        groupLoans.recordRepayment(active.getId(), 400_00, AGENT_ID, null, null);
        SavingsAccount account = accountFor(active.getCustomerId());

        assertThrows(IllegalArgumentException.class,
                () -> groupLoans.writeOff(active.getId(), MANAGER_ID, "Absconded", account.getId(), 100_01));
        assertEquals(GroupLoanStatus.ACTIVE, groupLoans.findById(active.getId()).getStatus());
        assertEquals(100_00, accounts.findById(account.getId()).getBalance());
    }

    @Test
    void writeOffRefusesToApplyMoreThanTheOutstandingBalance() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-116");
        GroupLoan active = activate(issue(group, newCustomer("Mawusi", "Kpodo"), 1000_00, 300_00, 100_00));
        groupLoans.recordRepayment(active.getId(), 900_00, AGENT_ID, null, null);
        SavingsAccount account = accountFor(active.getCustomerId());

        assertThrows(IllegalArgumentException.class,
                () -> groupLoans.writeOff(active.getId(), MANAGER_ID, "Absconded", account.getId(), 200_00));
        assertEquals(GroupLoanStatus.ACTIVE, groupLoans.findById(active.getId()).getStatus());
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
    void refusesASecondLoanWhileTheFirstIsStillADraft() throws Exception {
        LoanGroup group = newLoanGroup("LGRP-190");
        Customer customer = newCustomer("Akua", "Darko");
        GroupLoan draft = issue(group, customer, 1000_00, 100_00, 100_00);

        IllegalStateException refused = assertThrows(IllegalStateException.class,
                () -> issue(group, customer, 500_00, 50_00, 50_00));
        assertTrue(refused.getMessage().contains(draft.getLoanNumber()));
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
