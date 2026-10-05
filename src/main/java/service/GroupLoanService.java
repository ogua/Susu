package service;

import db.DatabaseConnection;
import enums.AccountStatus;
import enums.DepositStatus;
import enums.GroupLoanStatus;
import enums.InstallmentStatus;
import enums.LoanFrequency;
import enums.PaymentMethod;
import enums.TransactionType;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;
import java.util.UUID;
import models.GroupLoan;
import models.GroupLoanDeposit;
import models.GroupLoanInstallment;
import models.GroupLoanRepayment;
import models.JournalEntry;
import models.LoanGroup;
import models.LoanGroupMember;
import models.SavingsAccount;
import org.json.JSONObject;

/**
 * Group loan lifecycle — one loan per group member. The member enters a total
 * principal, a security deposit, and a directly-entered periodic repayment
 * amount; there is no product, no interest, no equal-split. A parity port of
 * the backend's rebuilt {@code App\Actions\GroupLoans\*Action} classes: the
 * local engine runs first (managers decide offline), hybrid mode pushes each
 * step through the outbox afterward as an audit trail.
 *
 * Lifecycle: issue -&gt; recordDeposit (credited into a chosen savings account)
 * -&gt; activate -&gt; recordRepayment -&gt; (auto) close. writeOff can draw a chosen
 * amount down from the member's savings first, then bad-debts the residual.
 */
public class GroupLoanService {

    private final LedgerService ledger = new LedgerService();
    private final ChartOfAccounts chart = new ChartOfAccounts();
    private final PeriodicScheduleGenerator scheduleGenerator = new PeriodicScheduleGenerator();
    private final LoanGroupService loanGroupService = new LoanGroupService();
    private final CustomerService customerService = new CustomerService();
    private final SavingsAccountService savingsAccountService = new SavingsAccountService();
    private final OutboxService outbox = new OutboxService();

    // ---------------------------------------------------------------- issue

    public GroupLoan issue(String agentId, String loanGroupId, String customerId, long principal,
                            long securityDeposit, long periodicAmount, LoanFrequency frequency, LocalDate startDate,
                            String notes, String clientReference) throws SQLException {
        String effectiveClientReference = clientReference != null ? clientReference : UUID.randomUUID().toString();

        GroupLoan existing = findByClientReference(effectiveClientReference);
        if (existing != null) {
            return existing;
        }

        if (principal <= 0) {
            throw new IllegalArgumentException("The loan amount must be greater than zero.");
        }
        if (periodicAmount <= 0) {
            throw new IllegalArgumentException("The periodic amount must be greater than zero.");
        }
        if (securityDeposit < 0) {
            throw new IllegalArgumentException("The security deposit cannot be negative.");
        }
        if (startDate.isBefore(LocalDate.now())) {
            throw new IllegalArgumentException("The first payment date cannot be in the past.");
        }

        LoanGroup group = loanGroupService.findById(loanGroupId);
        if (group == null || !group.isActive()) {
            throw new IllegalArgumentException("This loan group is not active.");
        }

        LoanGroupMember member = resolveMember(loanGroupId, customerId);
        GroupLoan openLoan = findOpenLoan(member.getId());
        if (openLoan != null) {
            throw new IllegalStateException(openLoan.getStatus() == GroupLoanStatus.DRAFT
                    ? "This member already has loan " + openLoan.getLoanNumber()
                            + " awaiting its security deposit and activation."
                    : "This member already has an active loan in the group.");
        }

        int totalPeriods = scheduleGenerator.periodCount(principal, periodicAmount);

        String id = effectiveClientReference;
        String now = Instant.now().toString();
        String loanNumber;

        try (Connection conn = DatabaseConnection.getConnection()) {
            loanNumber = nextLoanNumber(conn);

            String sql = "INSERT INTO group_loans (id, loan_group_id, loan_group_member_id, customer_id, agent_id,"
                    + " loan_number, principal_amount, security_deposit_amount, periodic_amount, outstanding_balance,"
                    + " repayment_frequency, start_date, total_periods, deposit_status, status, notes, client_reference,"
                    + " issued_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, loanGroupId);
                ps.setString(3, member.getId());
                ps.setString(4, customerId);
                ps.setString(5, agentId);
                ps.setString(6, loanNumber);
                ps.setLong(7, principal);
                ps.setLong(8, securityDeposit);
                ps.setLong(9, periodicAmount);
                ps.setString(10, frequency.value());
                ps.setString(11, startDate.format(DateTimeFormatter.ISO_LOCAL_DATE));
                ps.setInt(12, totalPeriods);
                ps.setString(13, securityDeposit > 0 ? DepositStatus.PENDING.value() : DepositStatus.HELD.value());
                ps.setString(14, GroupLoanStatus.DRAFT.value());
                ps.setString(15, notes);
                ps.setString(16, effectiveClientReference);
                ps.setString(17, now);
                ps.setString(18, now);
                ps.setString(19, now);
                ps.executeUpdate();
            }
        }

        outbox.enqueueIfHybrid("group_loan.issue", new JSONObject()
                .put("loan_group_id", loanGroupId)
                .put("customer_id", customerId)
                .put("principal_amount", principal)
                .put("security_deposit_amount", securityDeposit)
                .put("periodic_amount", periodicAmount)
                .put("repayment_frequency", frequency.value())
                .put("start_date", startDate.format(DateTimeFormatter.ISO_LOCAL_DATE))
                .put("notes", notes)
                .put("client_reference", effectiveClientReference));

        return findById(id);
    }

    // -------------------------------------------------------- record deposit

    /**
     * Records the member paying in their agreed security deposit, crediting it
     * straight into one of their savings accounts (Dr branch cash / Cr that
     * account's savings liability) — it becomes ordinary savings once posted.
     */
    public GroupLoan recordDeposit(String groupLoanId, String savingsAccountId, long amount, String recordedBy,
                                    String clientReference, Instant recordedAt) throws SQLException {
        String effectiveClientReference = clientReference != null ? clientReference : UUID.randomUUID().toString();

        if (findDepositByClientReference(effectiveClientReference) != null) {
            return findById(groupLoanId);
        }

        GroupLoan groupLoan = findById(groupLoanId);
        if (groupLoan == null || groupLoan.getStatus() != GroupLoanStatus.DRAFT) {
            throw new IllegalStateException("A deposit can only be recorded while the loan is a draft.");
        }
        if (groupLoan.getDepositStatus() != DepositStatus.PENDING) {
            throw new IllegalStateException("This loan's security deposit has already been recorded.");
        }
        if (amount <= 0 || amount != groupLoan.getSecurityDepositAmount()) {
            throw new IllegalArgumentException(
                    "The deposit must be paid in full (" + groupLoan.getSecurityDepositAmount() + " pesewas).");
        }
        SavingsAccount account = requireBorrowerAccount(groupLoan.getCustomerId(), savingsAccountId);
        if (account.getStatus() == AccountStatus.CLOSED) {
            throw new IllegalArgumentException("This savings account is closed.");
        }

        Instant effectiveRecordedAt = recordedAt != null ? recordedAt : Instant.now();

        List<LedgerLine> lines = new ArrayList<>();
        lines.add(LedgerLine.debit(chart.branchCash().getId(), amount));
        lines.add(LedgerLine.credit(account.getLedgerAccountId(), amount));

        JournalEntry entry = ledger.post(EntryRequest.of(TransactionType.GROUP_LOAN_DEPOSIT_HELD, lines)
                .paymentMethod(PaymentMethod.CASH)
                .recordedBy(recordedBy)
                .recordedAt(effectiveRecordedAt)
                .clientReference(effectiveClientReference)
                .description("Group loan security deposit " + groupLoan.getLoanNumber()));

        try (Connection conn = DatabaseConnection.getConnection()) {
            String now = Instant.now().toString();
            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE savings_accounts SET balance = balance + ?, updated_at = ? WHERE id = ?")) {
                ps.setLong(1, amount);
                ps.setString(2, now);
                ps.setString(3, account.getId());
                ps.executeUpdate();
            }
            insertDeposit(conn, groupLoan.getId(), account.getId(), entry.getId(), recordedBy, amount,
                    effectiveRecordedAt, effectiveClientReference);
            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE group_loans SET deposit_status = ?, updated_at = ? WHERE id = ?")) {
                ps.setString(1, DepositStatus.HELD.value());
                ps.setString(2, now);
                ps.setString(3, groupLoan.getId());
                ps.executeUpdate();
            }
        }

        outbox.enqueueIfHybrid("group_loan.deposit.record", new JSONObject()
                .put("group_loan_id", groupLoanId)
                .put("savings_account_id", account.getId())
                .put("amount", amount)
                .put("client_reference", effectiveClientReference)
                .put("recorded_at", effectiveRecordedAt.toString()));

        return findById(groupLoanId);
    }

    // -------------------------------------------------------------- activate

    public GroupLoan activate(String groupLoanId, String activatedBy) throws SQLException {
        GroupLoan groupLoan = findById(groupLoanId);
        if (groupLoan == null || groupLoan.getStatus() != GroupLoanStatus.DRAFT) {
            throw new IllegalStateException("Only a draft group loan can be activated.");
        }
        if (groupLoan.getDepositStatus() != DepositStatus.HELD) {
            throw new IllegalStateException("Record the security deposit before activating the loan.");
        }

        Instant activatedAt = Instant.now();
        List<ScheduledInstallment> schedule = scheduleGenerator.generate(
                groupLoan.getPrincipalAmount(), groupLoan.getPeriodicAmount(),
                groupLoan.getRepaymentFrequency(), groupLoan.getStartDate());

        String receivableAccountId = chart.groupLoanReceivable(groupLoan.getId(), groupLoan.getLoanNumber()).getId();

        List<LedgerLine> lines = new ArrayList<>();
        lines.add(LedgerLine.debit(receivableAccountId, groupLoan.getPrincipalAmount()));
        lines.add(LedgerLine.credit(chart.branchCash().getId(), groupLoan.getPrincipalAmount()));

        ledger.post(EntryRequest.of(TransactionType.GROUP_LOAN_DISBURSEMENT, lines)
                .paymentMethod(PaymentMethod.CASH)
                .recordedBy(activatedBy)
                .recordedAt(activatedAt)
                .description("Group loan disbursement " + groupLoan.getLoanNumber()));

        try (Connection conn = DatabaseConnection.getConnection()) {
            for (ScheduledInstallment installment : schedule) {
                String instNow = Instant.now().toString();
                try (PreparedStatement ps = conn.prepareStatement(
                        "INSERT INTO group_loan_installments (id, group_loan_id, sequence, due_date, amount_due,"
                        + " amount_paid, status, created_at, updated_at) VALUES (?,?,?,?,?,0,?,?,?)")) {
                    ps.setString(1, UUID.randomUUID().toString());
                    ps.setString(2, groupLoan.getId());
                    ps.setInt(3, installment.sequence());
                    ps.setString(4, installment.dueDate().format(DateTimeFormatter.ISO_LOCAL_DATE));
                    ps.setLong(5, installment.principalDue());
                    ps.setString(6, InstallmentStatus.PENDING.value());
                    ps.setString(7, instNow);
                    ps.setString(8, instNow);
                    ps.executeUpdate();
                }
            }

            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE group_loans SET receivable_account_id = ?, outstanding_balance = ?, total_periods = ?,"
                    + " status = ?, activated_by = ?, activated_at = ?, updated_at = ? WHERE id = ?")) {
                ps.setString(1, receivableAccountId);
                ps.setLong(2, groupLoan.getPrincipalAmount());
                ps.setInt(3, schedule.size());
                ps.setString(4, GroupLoanStatus.ACTIVE.value());
                ps.setString(5, activatedBy);
                ps.setString(6, activatedAt.toString());
                ps.setString(7, Instant.now().toString());
                ps.setString(8, groupLoan.getId());
                ps.executeUpdate();
            }
        }

        outbox.enqueueIfHybrid("group_loan.activate", new JSONObject().put("group_loan_id", groupLoanId));

        return findById(groupLoanId);
    }

    // ------------------------------------------------------ record repayment

    public GroupLoanRepaymentResult recordRepayment(String groupLoanId, long amount, String recordedBy,
                                                      String clientReference, Instant recordedAt) throws SQLException {
        String effectiveClientReference = clientReference != null ? clientReference : UUID.randomUUID().toString();

        GroupLoanRepayment existing = findRepaymentByClientReference(effectiveClientReference);
        if (existing != null) {
            JournalEntry entry = ledger.findByClientReference(effectiveClientReference);
            return new GroupLoanRepaymentResult(entry, findById(existing.getGroupLoanId()), existing, true);
        }

        GroupLoan groupLoan = findById(groupLoanId);
        if (groupLoan == null || groupLoan.getStatus() != GroupLoanStatus.ACTIVE) {
            throw new IllegalStateException("Only an active group loan can receive repayments.");
        }
        if (amount <= 0 || amount > groupLoan.getOutstandingBalance()) {
            throw new IllegalArgumentException("The amount must be positive and cannot exceed the outstanding balance.");
        }

        Instant effectiveRecordedAt = recordedAt != null ? recordedAt : Instant.now();
        applyToInstallments(groupLoan.getId(), amount);

        List<LedgerLine> lines = new ArrayList<>();
        lines.add(LedgerLine.debit(chart.branchCash().getId(), amount));
        lines.add(LedgerLine.credit(groupLoan.getReceivableAccountId(), amount));

        JournalEntry entry = ledger.post(EntryRequest.of(TransactionType.GROUP_LOAN_REPAYMENT, lines)
                .paymentMethod(PaymentMethod.CASH)
                .recordedBy(recordedBy)
                .recordedAt(effectiveRecordedAt)
                .clientReference(effectiveClientReference)
                .description("Group loan repayment " + groupLoan.getLoanNumber()));

        String repaymentId = UUID.randomUUID().toString();
        long newOutstanding = groupLoan.getOutstandingBalance() - amount;

        try (Connection conn = DatabaseConnection.getConnection()) {
            String now = Instant.now().toString();
            try (PreparedStatement ps = conn.prepareStatement(
                    "INSERT INTO group_loan_repayments (id, group_loan_id, journal_entry_id, recorded_by, amount,"
                    + " recorded_at, client_reference, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)")) {
                ps.setString(1, repaymentId);
                ps.setString(2, groupLoan.getId());
                ps.setString(3, entry.getId());
                ps.setString(4, recordedBy);
                ps.setLong(5, amount);
                ps.setString(6, effectiveRecordedAt.toString());
                ps.setString(7, effectiveClientReference);
                ps.setString(8, now);
                ps.setString(9, now);
                ps.executeUpdate();
            }

            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE group_loans SET outstanding_balance = ?, updated_at = ? WHERE id = ?")) {
                ps.setLong(1, newOutstanding);
                ps.setString(2, now);
                ps.setString(3, groupLoan.getId());
                ps.executeUpdate();
            }
        }

        if (newOutstanding <= 0) {
            close(groupLoan.getId(), recordedBy);
        }

        outbox.enqueueIfHybrid("group_loan.repayment.record", new JSONObject()
                .put("group_loan_id", groupLoan.getId())
                .put("amount", amount)
                .put("client_reference", effectiveClientReference)
                .put("recorded_at", effectiveRecordedAt.toString()));

        return new GroupLoanRepaymentResult(entry, findById(groupLoan.getId()), findRepaymentById(repaymentId), false);
    }

    // ----------------------------------------------------------------- close

    /**
     * Closes a fully-repaid loan. Any deposit paid in earlier already lives in
     * the member's ordinary savings, so there is nothing to refund — this is a
     * pure status flip.
     */
    public GroupLoan close(String groupLoanId, String closedBy) throws SQLException {
        GroupLoan groupLoan = findById(groupLoanId);
        if (groupLoan == null || groupLoan.getStatus() != GroupLoanStatus.ACTIVE) {
            throw new IllegalStateException("Only an active group loan can be closed.");
        }
        if (groupLoan.getOutstandingBalance() > 0) {
            throw new IllegalStateException("This group loan still has an outstanding balance.");
        }

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE group_loans SET status = ?, closed_at = ?, updated_at = ? WHERE id = ?")) {
            String now = Instant.now().toString();
            ps.setString(1, GroupLoanStatus.CLOSED.value());
            ps.setString(2, now);
            ps.setString(3, now);
            ps.setString(4, groupLoanId);
            ps.executeUpdate();
        }

        return findById(groupLoanId);
    }

    // ------------------------------------------------------------- write off

    /** Write-off with no savings applied — the whole outstanding balance is booked as bad debt. */
    public GroupLoan writeOff(String groupLoanId, String writtenOffBy, String reason) throws SQLException {
        return writeOff(groupLoanId, writtenOffBy, reason, null, 0);
    }

    /**
     * Declares an active loan's remaining balance uncollectible. An amount of
     * the member's own savings can be applied first (Dr savings liability / Cr
     * receivable); only the residual is booked as bad debt.
     */
    public GroupLoan writeOff(String groupLoanId, String writtenOffBy, String reason, String savingsAccountId,
                               long savingsAmountApplied) throws SQLException {
        GroupLoan groupLoan = findById(groupLoanId);
        if (groupLoan == null || groupLoan.getStatus() != GroupLoanStatus.ACTIVE) {
            throw new IllegalStateException("Only an active group loan can be written off.");
        }
        if (groupLoan.getOutstandingBalance() <= 0) {
            throw new IllegalStateException("This group loan has no outstanding balance to write off.");
        }

        long outstanding = groupLoan.getOutstandingBalance();
        long applied = 0;

        if (savingsAccountId != null && savingsAmountApplied > 0) {
            requireBorrowerAccount(groupLoan.getCustomerId(), savingsAccountId);
            if (savingsAmountApplied > outstanding) {
                throw new IllegalArgumentException("Cannot apply more than the outstanding balance.");
            }
            savingsAccountService.applyToLoanReceivable(savingsAccountId, groupLoan.getReceivableAccountId(),
                    savingsAmountApplied, writtenOffBy, TransactionType.SAVINGS_APPLIED_TO_GROUP_LOAN_WRITE_OFF,
                    "Savings applied to write-off " + groupLoan.getLoanNumber());
            applied = savingsAmountApplied;
        }

        long residual = outstanding - applied;
        if (residual > 0) {
            List<LedgerLine> lines = new ArrayList<>();
            lines.add(LedgerLine.debit(chart.badDebtExpense().getId(), residual));
            lines.add(LedgerLine.credit(groupLoan.getReceivableAccountId(), residual));

            ledger.post(EntryRequest.of(TransactionType.GROUP_LOAN_WRITE_OFF, lines)
                    .paymentMethod(PaymentMethod.INTERNAL)
                    .recordedBy(writtenOffBy)
                    .recordedAt(Instant.now())
                    .description("Group loan write-off " + groupLoan.getLoanNumber()));
        }

        String appliedAccountId = applied > 0 ? savingsAccountId : null;

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE group_loans SET status = ?, outstanding_balance = 0, written_off_at = ?,"
                     + " write_off_reason = ?, write_off_amount = ?, write_off_savings_account_id = ?,"
                     + " write_off_savings_applied = ?, updated_at = ? WHERE id = ?")) {
            String now = Instant.now().toString();
            ps.setString(1, GroupLoanStatus.WRITTEN_OFF.value());
            ps.setString(2, now);
            ps.setString(3, reason);
            ps.setLong(4, outstanding);
            ps.setString(5, appliedAccountId);
            ps.setLong(6, applied);
            ps.setString(7, now);
            ps.setString(8, groupLoanId);
            ps.executeUpdate();
        }

        JSONObject payload = new JSONObject()
                .put("group_loan_id", groupLoanId)
                .put("reason", reason);
        if (appliedAccountId != null) {
            payload.put("savings_account_id", appliedAccountId).put("savings_amount_applied", applied);
        }
        outbox.enqueueIfHybrid("group_loan.write_off", payload);

        return findById(groupLoanId);
    }

    // ------------------------------------------------------------------ cancel

    /**
     * Cancels a loan that was issued but never activated (mirrors the server's
     * CancelGroupLoanAction). Nothing was disbursed and any deposit paid already
     * sits in the member's savings, so no ledger entry is posted. Re-cancelling
     * is a no-op.
     */
    public GroupLoan cancel(String groupLoanId, String cancelledBy, String reason) throws SQLException {
        GroupLoan groupLoan = findById(groupLoanId);
        if (groupLoan == null) {
            throw new IllegalArgumentException("Group loan not found.");
        }
        if (groupLoan.getStatus() == GroupLoanStatus.CANCELLED) {
            return groupLoan;
        }
        if (groupLoan.getStatus() != GroupLoanStatus.DRAFT) {
            throw new IllegalStateException(
                    "Only a loan that has not been activated can be cancelled. Use write-off for an active loan.");
        }

        String cleanReason = reason == null || reason.isBlank() ? null : reason.trim();

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE group_loans SET status = ?, cancelled_at = ?, cancelled_by = ?, cancellation_reason = ?,"
                     + " updated_at = ? WHERE id = ? AND status = ?")) {
            String now = Instant.now().toString();
            ps.setString(1, GroupLoanStatus.CANCELLED.value());
            ps.setString(2, now);
            ps.setString(3, cancelledBy);
            ps.setString(4, cleanReason);
            ps.setString(5, now);
            ps.setString(6, groupLoanId);
            ps.setString(7, GroupLoanStatus.DRAFT.value());
            if (ps.executeUpdate() == 0) {
                throw new IllegalStateException("This loan is no longer a draft.");
            }
        }

        JSONObject payload = new JSONObject().put("group_loan_id", groupLoanId);
        if (cleanReason != null) {
            payload.put("reason", cleanReason);
        }
        outbox.enqueueIfHybrid("group_loan.cancel", payload);

        return findById(groupLoanId);
    }

    /**
     * Re-dates an active loan's unpaid installments — parity port of the
     * backend's RecalculateRepaymentScheduleAction for group loans. Amounts and
     * payments are untouched. Unpaid installments step one period apart from
     * {@code firstDueDate}, or — when null — from start_date (installment N on
     * start_date + N-1 periods). When the first installment is still unpaid,
     * start_date moves with it.
     *
     * @return the number of installments rescheduled
     */
    public int recalculateSchedule(String groupLoanId, String recalculatedBy, LocalDate firstDueDate, String reason)
            throws SQLException {
        GroupLoan groupLoan = findById(groupLoanId);
        if (groupLoan == null || groupLoan.getStatus() != GroupLoanStatus.ACTIVE) {
            throw new IllegalStateException("Only an active loan has a schedule to recalculate.");
        }
        List<GroupLoanInstallment> unpaid = findInstallments(groupLoanId).stream()
                .filter(installment -> installment.getStatus() != InstallmentStatus.PAID)
                .sorted(Comparator.comparingInt(GroupLoanInstallment::getSequence))
                .toList();
        if (unpaid.isEmpty()) {
            throw new IllegalStateException("Every installment is already paid — there is nothing to reschedule.");
        }

        LoanFrequency frequency = groupLoan.getRepaymentFrequency();
        LocalDate today = LocalDate.now();
        String now = Instant.now().toString();
        LocalDate dueDate = null;
        LocalDate firstRescheduled = null;

        try (Connection conn = DatabaseConnection.getConnection()) {
            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE group_loan_installments SET due_date = ?, status = ?, updated_at = ? WHERE id = ?")) {
                for (GroupLoanInstallment installment : unpaid) {
                    if (dueDate != null) {
                        dueDate = frequency.addPeriod(dueDate, 1);
                    } else if (firstDueDate != null) {
                        dueDate = firstDueDate;
                    } else {
                        dueDate = groupLoan.getStartDate();
                        for (int step = 1; step < installment.getSequence(); step++) {
                            dueDate = frequency.addPeriod(dueDate, 1);
                        }
                    }
                    if (firstRescheduled == null) {
                        firstRescheduled = dueDate;
                    }

                    InstallmentStatus status = installment.getStatus() == InstallmentStatus.OVERDUE && dueDate.isBefore(today)
                            ? InstallmentStatus.OVERDUE
                            : (installment.getAmountPaid() > 0 ? InstallmentStatus.PARTIALLY_PAID : InstallmentStatus.PENDING);

                    ps.setString(1, dueDate.format(DateTimeFormatter.ISO_LOCAL_DATE));
                    ps.setString(2, status.value());
                    ps.setString(3, now);
                    ps.setString(4, installment.getId());
                    ps.executeUpdate();
                }
            }

            if (unpaid.get(0).getSequence() == 1) {
                try (PreparedStatement ps = conn.prepareStatement(
                        "UPDATE group_loans SET start_date = ?, updated_at = ? WHERE id = ?")) {
                    ps.setString(1, firstRescheduled.format(DateTimeFormatter.ISO_LOCAL_DATE));
                    ps.setString(2, now);
                    ps.setString(3, groupLoanId);
                    ps.executeUpdate();
                }
            }
        }

        outbox.enqueueIfHybrid("group_loan.schedule.recalculate", new JSONObject()
                .put("group_loan_id", groupLoanId)
                .putOpt("first_due_date", firstDueDate != null ? firstDueDate.toString() : null)
                .putOpt("reason", reason));

        return unpaid.size();
    }

    // --------------------------------------------------------------- helpers

    private LoanGroupMember resolveMember(String loanGroupId, String customerId) throws SQLException {
        for (LoanGroupMember member : loanGroupService.findMembers(loanGroupId)) {
            if (member.getCustomerId().equals(customerId)) {
                if (!"active".equals(member.getStatus())) {
                    return loanGroupService.reactivateMember(member.getId());
                }
                return member;
            }
        }
        return loanGroupService.addMember(loanGroupId, customerId);
    }

    /**
     * The member's in-flight loan — a draft still awaiting its deposit/activation,
     * or an active loan — or null when a new loan may be issued (mirrors the server).
     */
    private GroupLoan findOpenLoan(String loanGroupMemberId) throws SQLException {
        String id;
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT id FROM group_loans WHERE loan_group_member_id = ? AND status IN (?, ?) LIMIT 1")) {
            ps.setString(1, loanGroupMemberId);
            ps.setString(2, GroupLoanStatus.DRAFT.value());
            ps.setString(3, GroupLoanStatus.ACTIVE.value());
            try (ResultSet rs = ps.executeQuery()) {
                if (!rs.next()) {
                    return null;
                }
                id = rs.getString("id");
            }
        }
        return findById(id);
    }

    /** Loads a savings account and rejects one that isn't the borrower's own. Call outside any open connection. */
    private SavingsAccount requireBorrowerAccount(String customerId, String savingsAccountId) throws SQLException {
        SavingsAccount account = savingsAccountId != null ? savingsAccountService.findById(savingsAccountId) : null;
        if (account == null) {
            throw new IllegalArgumentException("Select one of the borrower's savings accounts.");
        }
        if (!account.getCustomerId().equals(customerId)) {
            throw new IllegalArgumentException("This savings account does not belong to the borrower.");
        }
        return account;
    }

    private void insertDeposit(Connection conn, String groupLoanId, String savingsAccountId, String journalEntryId,
                                String recordedBy, long amount, Instant recordedAt, String clientReference)
            throws SQLException {
        String now = Instant.now().toString();
        try (PreparedStatement ps = conn.prepareStatement(
                "INSERT INTO group_loan_deposits (id, group_loan_id, savings_account_id, journal_entry_id, recorded_by,"
                + " amount, type, recorded_at, client_reference, created_at, updated_at)"
                + " VALUES (?,?,?,?,?,?,'held',?,?,?,?)")) {
            ps.setString(1, UUID.randomUUID().toString());
            ps.setString(2, groupLoanId);
            ps.setString(3, savingsAccountId);
            ps.setString(4, journalEntryId);
            ps.setString(5, recordedBy);
            ps.setLong(6, amount);
            ps.setString(7, recordedAt.toString());
            ps.setString(8, clientReference);
            ps.setString(9, now);
            ps.setString(10, now);
            ps.executeUpdate();
        }
    }

    /** Applies a payment across the member's installments oldest-first (pure principal). */
    private void applyToInstallments(String groupLoanId, long amount) throws SQLException {
        long remaining = amount;

        List<GroupLoanInstallment> installments = findInstallments(groupLoanId).stream()
                .filter(i -> i.getStatus() == InstallmentStatus.PENDING
                        || i.getStatus() == InstallmentStatus.PARTIALLY_PAID
                        || i.getStatus() == InstallmentStatus.OVERDUE)
                .toList();

        for (GroupLoanInstallment installment : installments) {
            if (remaining <= 0) {
                break;
            }
            long portion = Math.min(installment.remaining(), remaining);
            if (portion <= 0) {
                continue;
            }

            boolean wasOverdue = installment.getStatus() == InstallmentStatus.OVERDUE;
            long newAmountPaid = installment.getAmountPaid() + portion;

            InstallmentStatus newStatus;
            Instant paidAt = null;
            if (newAmountPaid >= installment.getAmountDue()) {
                newStatus = InstallmentStatus.PAID;
                paidAt = Instant.now();
            } else if (wasOverdue) {
                newStatus = InstallmentStatus.OVERDUE;
            } else {
                newStatus = InstallmentStatus.PARTIALLY_PAID;
            }

            try (Connection conn = DatabaseConnection.getConnection();
                 PreparedStatement ps = conn.prepareStatement(
                         "UPDATE group_loan_installments SET amount_paid = ?, status = ?, paid_at = ?, updated_at = ?"
                         + " WHERE id = ?")) {
                ps.setLong(1, newAmountPaid);
                ps.setString(2, newStatus.value());
                ps.setString(3, paidAt != null ? paidAt.toString() : null);
                ps.setString(4, Instant.now().toString());
                ps.setString(5, installment.getId());
                ps.executeUpdate();
            }

            remaining -= portion;
        }
    }

    /** GL-{sequence} — GL distinguishes a group member loan from an individual loan's LN prefix. */
    private String nextLoanNumber(Connection conn) throws SQLException {
        int sequence;
        try (PreparedStatement ps = conn.prepareStatement("SELECT COUNT(*) FROM group_loans");
             ResultSet rs = ps.executeQuery()) {
            rs.next();
            sequence = rs.getInt(1) + 1;
        }

        String number;
        do {
            number = "GL-" + String.format("%05d", sequence);
            sequence++;
        } while (numberExists(conn, number));

        return number;
    }

    private boolean numberExists(Connection conn, String number) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement("SELECT 1 FROM group_loans WHERE loan_number = ?")) {
            ps.setString(1, number);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next();
            }
        }
    }

    // ----------------------------------------------------------------- reads

    public GroupLoan findById(String id) throws SQLException {
        GroupLoan groupLoan;
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM group_loans WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                if (!rs.next()) {
                    return null;
                }
                groupLoan = map(rs);
            }
        }

        groupLoan.setLoanGroup(loanGroupService.findById(groupLoan.getLoanGroupId()));
        groupLoan.setCustomer(customerService.findById(groupLoan.getCustomerId()));
        groupLoan.setInstallments(findInstallments(id));
        return groupLoan;
    }

    public List<GroupLoan> findAll() throws SQLException {
        List<GroupLoan> groupLoans = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM group_loans ORDER BY issued_at DESC");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                groupLoans.add(map(rs));
            }
        }
        for (GroupLoan groupLoan : groupLoans) {
            groupLoan.setLoanGroup(loanGroupService.findById(groupLoan.getLoanGroupId()));
            groupLoan.setCustomer(customerService.findById(groupLoan.getCustomerId()));
        }
        return groupLoans;
    }

    public List<GroupLoan> findByLoanGroup(String loanGroupId) throws SQLException {
        List<GroupLoan> groupLoans = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM group_loans WHERE loan_group_id = ? ORDER BY issued_at DESC")) {
            ps.setString(1, loanGroupId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    groupLoans.add(map(rs));
                }
            }
        }
        for (GroupLoan groupLoan : groupLoans) {
            groupLoan.setCustomer(customerService.findById(groupLoan.getCustomerId()));
        }
        return groupLoans;
    }

    /** Sum of every active member loan's outstanding balance — the group's total debt. */
    public long groupOutstanding(String loanGroupId) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT COALESCE(SUM(outstanding_balance), 0) FROM group_loans"
                     + " WHERE loan_group_id = ? AND status = ?")) {
            ps.setString(1, loanGroupId);
            ps.setString(2, GroupLoanStatus.ACTIVE.value());
            try (ResultSet rs = ps.executeQuery()) {
                rs.next();
                return rs.getLong(1);
            }
        }
    }

    public List<GroupLoanInstallment> findInstallments(String groupLoanId) throws SQLException {
        List<GroupLoanInstallment> installments = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM group_loan_installments WHERE group_loan_id = ? ORDER BY sequence")) {
            ps.setString(1, groupLoanId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    installments.add(mapInstallment(rs));
                }
            }
        }
        return installments;
    }

    private GroupLoanRepayment findRepaymentById(String id) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM group_loan_repayments WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? mapRepayment(rs) : null;
            }
        }
    }

    private GroupLoanRepayment findRepaymentByClientReference(String clientReference) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM group_loan_repayments WHERE client_reference = ?")) {
            ps.setString(1, clientReference);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? mapRepayment(rs) : null;
            }
        }
    }

    private GroupLoanDeposit findDepositByClientReference(String clientReference) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM group_loan_deposits WHERE client_reference = ?")) {
            ps.setString(1, clientReference);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? mapDeposit(rs) : null;
            }
        }
    }

    private GroupLoan findByClientReference(String clientReference) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM group_loans WHERE client_reference = ?")) {
            ps.setString(1, clientReference);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? map(rs) : null;
            }
        }
    }

    private GroupLoan map(ResultSet rs) throws SQLException {
        GroupLoan groupLoan = new GroupLoan();
        groupLoan.setId(rs.getString("id"));
        groupLoan.setLoanGroupId(rs.getString("loan_group_id"));
        groupLoan.setLoanGroupMemberId(rs.getString("loan_group_member_id"));
        groupLoan.setCustomerId(rs.getString("customer_id"));
        groupLoan.setAgentId(rs.getString("agent_id"));
        groupLoan.setActivatedBy(rs.getString("activated_by"));
        groupLoan.setReceivableAccountId(rs.getString("receivable_account_id"));
        groupLoan.setLoanNumber(rs.getString("loan_number"));
        groupLoan.setPrincipalAmount(rs.getLong("principal_amount"));
        groupLoan.setSecurityDepositAmount(rs.getLong("security_deposit_amount"));
        groupLoan.setPeriodicAmount(rs.getLong("periodic_amount"));
        groupLoan.setOutstandingBalance(rs.getLong("outstanding_balance"));
        groupLoan.setRepaymentFrequency(LoanFrequency.fromValue(rs.getString("repayment_frequency")));
        groupLoan.setStartDate(LocalDate.parse(rs.getString("start_date")));
        groupLoan.setTotalPeriods(rs.getInt("total_periods"));
        groupLoan.setDepositStatus(DepositStatus.fromValue(rs.getString("deposit_status")));
        groupLoan.setStatus(GroupLoanStatus.fromValue(rs.getString("status")));
        groupLoan.setNotes(rs.getString("notes"));
        groupLoan.setClientReference(rs.getString("client_reference"));
        groupLoan.setIssuedAt(Instant.parse(rs.getString("issued_at")));
        String activatedAt = rs.getString("activated_at");
        groupLoan.setActivatedAt(activatedAt != null ? Instant.parse(activatedAt) : null);
        String closedAt = rs.getString("closed_at");
        groupLoan.setClosedAt(closedAt != null ? Instant.parse(closedAt) : null);
        String writtenOffAt = rs.getString("written_off_at");
        groupLoan.setWrittenOffAt(writtenOffAt != null ? Instant.parse(writtenOffAt) : null);
        groupLoan.setWriteOffReason(rs.getString("write_off_reason"));
        long writeOffAmount = rs.getLong("write_off_amount");
        groupLoan.setWriteOffAmount(rs.wasNull() ? null : writeOffAmount);
        groupLoan.setWriteOffSavingsAccountId(rs.getString("write_off_savings_account_id"));
        long writeOffSavingsApplied = rs.getLong("write_off_savings_applied");
        groupLoan.setWriteOffSavingsApplied(rs.wasNull() ? null : writeOffSavingsApplied);
        String cancelledAt = rs.getString("cancelled_at");
        groupLoan.setCancelledAt(cancelledAt != null ? Instant.parse(cancelledAt) : null);
        groupLoan.setCancelledBy(rs.getString("cancelled_by"));
        groupLoan.setCancellationReason(rs.getString("cancellation_reason"));
        return groupLoan;
    }

    private GroupLoanInstallment mapInstallment(ResultSet rs) throws SQLException {
        GroupLoanInstallment installment = new GroupLoanInstallment();
        installment.setId(rs.getString("id"));
        installment.setGroupLoanId(rs.getString("group_loan_id"));
        installment.setSequence(rs.getInt("sequence"));
        installment.setDueDate(LocalDate.parse(rs.getString("due_date")));
        installment.setAmountDue(rs.getLong("amount_due"));
        installment.setAmountPaid(rs.getLong("amount_paid"));
        installment.setStatus(InstallmentStatus.fromValue(rs.getString("status")));
        String paidAt = rs.getString("paid_at");
        installment.setPaidAt(paidAt != null ? Instant.parse(paidAt) : null);
        return installment;
    }

    private GroupLoanRepayment mapRepayment(ResultSet rs) throws SQLException {
        GroupLoanRepayment repayment = new GroupLoanRepayment();
        repayment.setId(rs.getString("id"));
        repayment.setGroupLoanId(rs.getString("group_loan_id"));
        repayment.setJournalEntryId(rs.getString("journal_entry_id"));
        repayment.setRecordedBy(rs.getString("recorded_by"));
        repayment.setAmount(rs.getLong("amount"));
        repayment.setRecordedAt(Instant.parse(rs.getString("recorded_at")));
        repayment.setClientReference(rs.getString("client_reference"));
        return repayment;
    }

    private GroupLoanDeposit mapDeposit(ResultSet rs) throws SQLException {
        GroupLoanDeposit deposit = new GroupLoanDeposit();
        deposit.setId(rs.getString("id"));
        deposit.setGroupLoanId(rs.getString("group_loan_id"));
        deposit.setSavingsAccountId(rs.getString("savings_account_id"));
        deposit.setJournalEntryId(rs.getString("journal_entry_id"));
        deposit.setRecordedBy(rs.getString("recorded_by"));
        deposit.setAmount(rs.getLong("amount"));
        deposit.setType(rs.getString("type"));
        deposit.setRecordedAt(Instant.parse(rs.getString("recorded_at")));
        deposit.setClientReference(rs.getString("client_reference"));
        return deposit;
    }
}
