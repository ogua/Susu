package service;

import db.DatabaseConnection;
import enums.GroupLoanStatus;
import enums.InstallmentStatus;
import enums.PaymentMethod;
import enums.TransactionType;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import models.GroupLoan;
import models.GroupLoanBorrower;
import models.GroupLoanInstallment;
import models.GroupLoanRepayment;
import models.JournalEntry;
import models.LoanGroupMember;
import models.LoanProduct;
import org.json.JSONObject;

/**
 * Group loan lifecycle: apply -> approve/reject -> disburse -> repayments —
 * joint & several liability under a shared schedule/receivable, split evenly
 * across the loan group's active members at disbursement. A parity port of
 * the backend's {@code App\Actions\GroupLoans\*Action} classes, mirroring
 * {@link LoanService}'s local-first-then-outbox shape exactly: approval and
 * disbursement run against the local engine (company_admin/branch_manager
 * decide offline), hybrid mode pushes the outcome through the outbox
 * afterward as an audit trail.
 */
public class GroupLoanService {

    private final LedgerService ledger = new LedgerService();
    private final ChartOfAccounts chart = new ChartOfAccounts();
    private final ScheduleGenerator scheduleGenerator = new ScheduleGenerator();
    private final LoanGroupService loanGroupService = new LoanGroupService();
    private final CustomerService customerService = new CustomerService();
    private final LoanProductService productService = new LoanProductService();
    private final OutboxService outbox = new OutboxService();

    public GroupLoan apply(String agentId, String loanGroupId, String productId, long requestedAmount,
                            String notes, String clientReference) throws SQLException {
        String effectiveClientReference = clientReference != null ? clientReference : UUID.randomUUID().toString();

        GroupLoan existing = findByClientReference(effectiveClientReference);
        if (existing != null) {
            return existing;
        }

        LoanProduct product = productService.findById(productId);
        if (product == null || !product.isActive()) {
            throw new IllegalArgumentException("This loan product is no longer offered.");
        }
        if (requestedAmount < product.getMinAmount() || requestedAmount > product.getMaxAmount()) {
            throw new IllegalArgumentException(
                    "Amount must be between " + product.getMinAmount() + " and " + product.getMaxAmount() + " pesewas.");
        }

        long activeMemberCount = loanGroupService.findMembers(loanGroupId).stream()
                .filter(m -> "active".equals(m.getStatus())).count();
        if (activeMemberCount < 2) {
            throw new IllegalArgumentException("A loan group needs at least 2 active members to apply for a group loan.");
        }

        // The local id doubles as client_reference, same reasoning as LoanService.apply.
        String id = effectiveClientReference;
        String now = Instant.now().toString();
        String loanNumber;

        try (Connection conn = DatabaseConnection.getConnection()) {
            loanNumber = nextLoanNumber(conn);

            String sql = "INSERT INTO group_loans (id, loan_group_id, loan_product_id, agent_id,"
                    + " loan_number, principal_amount, interest_method, interest_rate_bps, term_period_count,"
                    + " repayment_frequency, origination_fee_amount, penalty_rate_bps, grace_period_days,"
                    + " total_interest, total_repayable, outstanding_balance, status, notes, client_reference,"
                    + " applied_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,0,?,?,?,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, loanGroupId);
                ps.setString(3, productId);
                ps.setString(4, agentId);
                ps.setString(5, loanNumber);
                ps.setLong(6, requestedAmount);
                ps.setString(7, product.getInterestMethod().value());
                ps.setInt(8, product.getInterestRateBps());
                ps.setInt(9, product.getTermPeriodCount());
                ps.setString(10, product.getRepaymentFrequency().value());
                ps.setLong(11, product.getOriginationFeeAmount());
                ps.setInt(12, product.getPenaltyRateBps());
                ps.setInt(13, product.getGracePeriodDays());
                ps.setString(14, GroupLoanStatus.APPLIED.value());
                ps.setString(15, notes);
                ps.setString(16, effectiveClientReference);
                ps.setString(17, now);
                ps.setString(18, now);
                ps.setString(19, now);
                ps.executeUpdate();
            }
        }

        outbox.enqueueIfHybrid("group_loan.apply", new JSONObject()
                .put("loan_group_id", loanGroupId)
                .put("loan_product_id", productId)
                .put("amount", requestedAmount)
                .put("notes", notes)
                .put("client_reference", effectiveClientReference));

        return findById(id);
    }

    public GroupLoan approve(String groupLoanId, String approvedBy) throws SQLException {
        requireStatus(groupLoanId, GroupLoanStatus.APPLIED, "approved");

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE group_loans SET status = ?, approved_by = ?, approved_at = ?, updated_at = ? WHERE id = ?")) {
            ps.setString(1, GroupLoanStatus.APPROVED.value());
            ps.setString(2, approvedBy);
            ps.setString(3, Instant.now().toString());
            ps.setString(4, Instant.now().toString());
            ps.setString(5, groupLoanId);
            ps.executeUpdate();
        }

        outbox.enqueueIfHybrid("group_loan.approve", new JSONObject().put("group_loan_id", groupLoanId));

        return findById(groupLoanId);
    }

    public GroupLoan reject(String groupLoanId, String rejectedBy, String reason) throws SQLException {
        requireStatus(groupLoanId, GroupLoanStatus.APPLIED, "rejected");

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE group_loans SET status = ?, approved_by = ?, rejection_reason = ?, updated_at = ? WHERE id = ?")) {
            ps.setString(1, GroupLoanStatus.REJECTED.value());
            ps.setString(2, rejectedBy);
            ps.setString(3, reason);
            ps.setString(4, Instant.now().toString());
            ps.setString(5, groupLoanId);
            ps.executeUpdate();
        }

        outbox.enqueueIfHybrid("group_loan.reject", new JSONObject().put("group_loan_id", groupLoanId).put("reason", reason));

        return findById(groupLoanId);
    }

    public GroupLoan disburse(String groupLoanId, String disbursedBy) throws SQLException {
        GroupLoan groupLoan = requireStatus(groupLoanId, GroupLoanStatus.APPROVED, "disbursed");

        List<LoanGroupMember> members = loanGroupService.findMembers(groupLoan.getLoanGroupId()).stream()
                .filter(m -> "active".equals(m.getStatus()))
                .sorted((a, b) -> {
                    int byJoined = a.getJoinedAt().compareTo(b.getJoinedAt());
                    return byJoined != 0 ? byJoined : a.getId().compareTo(b.getId());
                })
                .toList();
        if (members.size() < 2) {
            throw new IllegalStateException("A group loan needs at least 2 active members to disburse.");
        }

        Instant disbursedAt = Instant.now();
        LocalDate disbursedDate = LocalDate.ofInstant(disbursedAt, ZoneId.systemDefault());

        List<ScheduledInstallment> schedule = scheduleGenerator.generate(
                groupLoan.getPrincipalAmount(), groupLoan.getInterestRateBps(), groupLoan.getTermPeriodCount(),
                groupLoan.getInterestMethod(), groupLoan.getRepaymentFrequency(), disbursedDate);

        long totalInterest = schedule.stream().mapToLong(ScheduledInstallment::interestDue).sum();
        long totalRepayable = groupLoan.getPrincipalAmount() + totalInterest;
        long netCash = groupLoan.getPrincipalAmount() - groupLoan.getOriginationFeeAmount();

        String receivableAccountId = chart.groupLoanReceivable(groupLoan.getId(), groupLoan.getLoanNumber()).getId();

        List<LedgerLine> lines = new ArrayList<>();
        lines.add(LedgerLine.debit(receivableAccountId, groupLoan.getPrincipalAmount()));
        lines.add(LedgerLine.credit(chart.branchCash().getId(), netCash));
        if (groupLoan.getOriginationFeeAmount() > 0) {
            lines.add(LedgerLine.credit(chart.loanFeeIncome().getId(), groupLoan.getOriginationFeeAmount()));
        }

        ledger.post(EntryRequest.of(TransactionType.GROUP_LOAN_DISBURSEMENT, lines)
                .paymentMethod(PaymentMethod.CASH)
                .recordedBy(disbursedBy)
                .recordedAt(disbursedAt)
                .description("Group loan disbursement " + groupLoan.getLoanNumber()));

        int memberCount = members.size();
        long sharePerMember = groupLoan.getPrincipalAmount() / memberCount;
        long shareRemainder = groupLoan.getPrincipalAmount() - (sharePerMember * memberCount);

        try (Connection conn = DatabaseConnection.getConnection()) {
            for (int i = 0; i < members.size(); i++) {
                LoanGroupMember member = members.get(i);
                long share = sharePerMember + (i == memberCount - 1 ? shareRemainder : 0);

                String sql = "INSERT INTO group_loan_borrowers (id, group_loan_id, loan_group_member_id, customer_id,"
                        + " share_principal, share_outstanding, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)";
                try (PreparedStatement ps = conn.prepareStatement(sql)) {
                    String now = Instant.now().toString();
                    ps.setString(1, UUID.randomUUID().toString());
                    ps.setString(2, groupLoan.getId());
                    ps.setString(3, member.getId());
                    ps.setString(4, member.getCustomerId());
                    ps.setLong(5, share);
                    ps.setLong(6, share);
                    ps.setString(7, now);
                    ps.setString(8, now);
                    ps.executeUpdate();
                }
            }

            for (ScheduledInstallment installment : schedule) {
                String sql = "INSERT INTO group_loan_installments (id, group_loan_id, sequence, due_date, principal_due,"
                        + " interest_due, penalty_due, principal_paid, interest_paid, penalty_paid, status,"
                        + " created_at, updated_at) VALUES (?,?,?,?,?,?,0,0,0,0,?,?,?)";
                try (PreparedStatement ps = conn.prepareStatement(sql)) {
                    String now = Instant.now().toString();
                    ps.setString(1, UUID.randomUUID().toString());
                    ps.setString(2, groupLoan.getId());
                    ps.setInt(3, installment.sequence());
                    ps.setString(4, installment.dueDate().format(DateTimeFormatter.ISO_LOCAL_DATE));
                    ps.setLong(5, installment.principalDue());
                    ps.setLong(6, installment.interestDue());
                    ps.setString(7, InstallmentStatus.PENDING.value());
                    ps.setString(8, now);
                    ps.setString(9, now);
                    ps.executeUpdate();
                }
            }

            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE group_loans SET receivable_account_id = ?, total_interest = ?, total_repayable = ?,"
                    + " outstanding_balance = ?, member_count_at_disbursement = ?, status = ?, disbursed_at = ?,"
                    + " updated_at = ? WHERE id = ?")) {
                ps.setString(1, receivableAccountId);
                ps.setLong(2, totalInterest);
                ps.setLong(3, totalRepayable);
                ps.setLong(4, totalRepayable);
                ps.setInt(5, memberCount);
                ps.setString(6, GroupLoanStatus.DISBURSED.value());
                ps.setString(7, disbursedAt.toString());
                ps.setString(8, Instant.now().toString());
                ps.setString(9, groupLoan.getId());
                ps.executeUpdate();
            }
        }

        outbox.enqueueIfHybrid("group_loan.disburse", new JSONObject().put("group_loan_id", groupLoanId));

        return findById(groupLoanId);
    }

    /**
     * Closes a disbursed group loan whose schedule isn't working and opens a
     * new linked group loan carrying over the old loan's outstanding
     * PRINCIPAL onto a fresh schedule under the chosen product's terms,
     * re-splitting it across the loan group's currently active members
     * (membership can drift since the original disbursement, so the
     * >=2-active-member guard is re-checked here too, same as {@link
     * #disburse}). Hybrid mode pushes the outcome through the outbox
     * afterward, same client-reference-as-id reasoning as
     * {@link LoanService#restructure}.
     */
    public GroupLoan restructure(String groupLoanId, String restructuredBy, String newProductId, String reason)
            throws SQLException {
        GroupLoan groupLoan = requireStatus(groupLoanId, GroupLoanStatus.DISBURSED, "restructured");

        LoanProduct newProduct = productService.findById(newProductId);
        if (newProduct == null || !newProduct.isActive()) {
            throw new IllegalArgumentException("This loan product is no longer offered.");
        }

        long principalOutstanding = accountBalance(groupLoan.getReceivableAccountId());
        if (principalOutstanding <= 0) {
            throw new IllegalStateException("Nothing to restructure — outstanding principal is already zero.");
        }

        List<LoanGroupMember> members = activeMembers(groupLoan.getLoanGroupId());
        if (members.size() < 2) {
            throw new IllegalStateException("A group loan needs at least 2 active members to restructure.");
        }

        Instant restructuredAt = Instant.now();
        LocalDate restructuredDate = LocalDate.ofInstant(restructuredAt, ZoneId.systemDefault());
        long refinanceAmount = groupLoan.getOutstandingBalance();

        List<ScheduledInstallment> schedule = scheduleGenerator.generate(
                principalOutstanding, newProduct.getInterestRateBps(), newProduct.getTermPeriodCount(),
                newProduct.getInterestMethod(), newProduct.getRepaymentFrequency(), restructuredDate);
        long totalInterest = schedule.stream().mapToLong(ScheduledInstallment::interestDue).sum();
        long totalRepayable = principalOutstanding + totalInterest;

        String newGroupLoanId = UUID.randomUUID().toString();
        String newLoanNumber;
        try (Connection conn = DatabaseConnection.getConnection()) {
            newLoanNumber = nextLoanNumber(conn);
        }

        String receivableAccountId = chart.groupLoanReceivable(newGroupLoanId, newLoanNumber).getId();

        List<LedgerLine> lines = new ArrayList<>();
        lines.add(LedgerLine.debit(receivableAccountId, principalOutstanding));
        lines.add(LedgerLine.credit(groupLoan.getReceivableAccountId(), principalOutstanding));

        ledger.post(EntryRequest.of(TransactionType.GROUP_LOAN_RESTRUCTURE, lines)
                .paymentMethod(PaymentMethod.CASH)
                .recordedBy(restructuredBy)
                .recordedAt(restructuredAt)
                .description("Group loan restructure " + groupLoan.getLoanNumber() + " -> " + newLoanNumber));

        String now = restructuredAt.toString();
        try (Connection conn = DatabaseConnection.getConnection()) {
            insertRefinancedGroupLoan(conn, newGroupLoanId, groupLoan.getLoanGroupId(), newProduct.getId(),
                    groupLoan.getAgentId(), restructuredBy, receivableAccountId, newLoanNumber, principalOutstanding,
                    newProduct.getInterestMethod().value(), newProduct.getInterestRateBps(),
                    newProduct.getTermPeriodCount(), newProduct.getRepaymentFrequency().value(),
                    newProduct.getOriginationFeeAmount(), newProduct.getPenaltyRateBps(),
                    newProduct.getGracePeriodDays(), totalInterest, totalRepayable, members.size(),
                    groupLoan.getId(), principalOutstanding, now);

            insertRefinancedBorrowers(conn, newGroupLoanId, members, principalOutstanding);
            insertRefinancedGroupInstallments(conn, newGroupLoanId, schedule);
            closeRefinancedGroupLoan(conn, groupLoan.getId(), "restructure", reason, refinanceAmount, now);
        }

        outbox.enqueueIfHybrid("group_loan.restructure", new JSONObject()
                .put("group_loan_id", groupLoanId)
                .put("loan_product_id", newProductId)
                .put("reason", reason)
                .put("client_reference", newGroupLoanId));

        return findById(newGroupLoanId);
    }

    /**
     * Closes a disbursed group loan in good standing and opens a new linked
     * group loan whose principal is the old loan's outstanding PRINCIPAL
     * plus a manager-entered top-up amount of fresh cash, reusing the OLD
     * loan's own terms and re-splitting the new principal across the loan
     * group's currently active members. Hybrid mode pushes the outcome
     * through the outbox afterward, same reasoning as {@link LoanService#topUp}.
     */
    public GroupLoan topUp(String groupLoanId, String toppedUpBy, long topUpAmount, String reason) throws SQLException {
        GroupLoan groupLoan = requireStatus(groupLoanId, GroupLoanStatus.DISBURSED, "topped up");
        if (topUpAmount <= 0) {
            throw new IllegalArgumentException("The top-up amount must be greater than zero.");
        }
        boolean hasOverdue = findInstallments(groupLoanId).stream()
                .anyMatch(i -> i.getStatus() == InstallmentStatus.OVERDUE);
        if (hasOverdue) {
            throw new IllegalStateException("This group loan has an overdue installment and is not eligible for a top-up.");
        }

        List<LoanGroupMember> members = activeMembers(groupLoan.getLoanGroupId());
        if (members.size() < 2) {
            throw new IllegalStateException("A group loan needs at least 2 active members to top up.");
        }

        long principalOutstanding = accountBalance(groupLoan.getReceivableAccountId());
        long newPrincipal = principalOutstanding + topUpAmount;

        Instant toppedUpAt = Instant.now();
        LocalDate toppedUpDate = LocalDate.ofInstant(toppedUpAt, ZoneId.systemDefault());
        long refinanceAmount = groupLoan.getOutstandingBalance();

        List<ScheduledInstallment> schedule = scheduleGenerator.generate(
                newPrincipal, groupLoan.getInterestRateBps(), groupLoan.getTermPeriodCount(),
                groupLoan.getInterestMethod(), groupLoan.getRepaymentFrequency(), toppedUpDate);
        long totalInterest = schedule.stream().mapToLong(ScheduledInstallment::interestDue).sum();
        long totalRepayable = newPrincipal + totalInterest;
        long netCash = topUpAmount - groupLoan.getOriginationFeeAmount();

        String newGroupLoanId = UUID.randomUUID().toString();
        String newLoanNumber;
        try (Connection conn = DatabaseConnection.getConnection()) {
            newLoanNumber = nextLoanNumber(conn);
        }

        String receivableAccountId = chart.groupLoanReceivable(newGroupLoanId, newLoanNumber).getId();

        List<LedgerLine> lines = new ArrayList<>();
        lines.add(LedgerLine.debit(receivableAccountId, newPrincipal));
        if (principalOutstanding > 0) {
            lines.add(LedgerLine.credit(groupLoan.getReceivableAccountId(), principalOutstanding));
        }
        lines.add(LedgerLine.credit(chart.branchCash().getId(), netCash));
        if (groupLoan.getOriginationFeeAmount() > 0) {
            lines.add(LedgerLine.credit(chart.loanFeeIncome().getId(), groupLoan.getOriginationFeeAmount()));
        }

        ledger.post(EntryRequest.of(TransactionType.GROUP_LOAN_TOP_UP, lines)
                .paymentMethod(PaymentMethod.CASH)
                .recordedBy(toppedUpBy)
                .recordedAt(toppedUpAt)
                .description("Group loan top-up " + groupLoan.getLoanNumber() + " -> " + newLoanNumber));

        String now = toppedUpAt.toString();
        try (Connection conn = DatabaseConnection.getConnection()) {
            insertRefinancedGroupLoan(conn, newGroupLoanId, groupLoan.getLoanGroupId(), groupLoan.getLoanProductId(),
                    groupLoan.getAgentId(), toppedUpBy, receivableAccountId, newLoanNumber, newPrincipal,
                    groupLoan.getInterestMethod().value(), groupLoan.getInterestRateBps(),
                    groupLoan.getTermPeriodCount(), groupLoan.getRepaymentFrequency().value(),
                    groupLoan.getOriginationFeeAmount(), groupLoan.getPenaltyRateBps(),
                    groupLoan.getGracePeriodDays(), totalInterest, totalRepayable, members.size(),
                    groupLoan.getId(), principalOutstanding, now);

            insertRefinancedBorrowers(conn, newGroupLoanId, members, newPrincipal);
            insertRefinancedGroupInstallments(conn, newGroupLoanId, schedule);
            closeRefinancedGroupLoan(conn, groupLoan.getId(), "top_up", reason, refinanceAmount, now);
        }

        outbox.enqueueIfHybrid("group_loan.top_up", new JSONObject()
                .put("group_loan_id", groupLoanId)
                .put("amount", topUpAmount)
                .put("reason", reason)
                .put("client_reference", newGroupLoanId));

        return findById(newGroupLoanId);
    }

    /**
     * Declares a disbursed group loan's remaining shared balance
     * uncollectible — mirrors {@link LoanService#writeOff} exactly,
     * including crediting the receivable by its actual current balance
     * (principal only) rather than the full outstanding_balance. Unlike a
     * repayment, this does NOT touch any individual GroupLoanBorrower's
     * share_outstanding: those remain as accountability history of what
     * each member still owed at the moment the group's joint debt was
     * written off.
     */
    public GroupLoan writeOff(String groupLoanId, String writtenOffBy, String reason) throws SQLException {
        GroupLoan groupLoan = requireStatus(groupLoanId, GroupLoanStatus.DISBURSED, "written off");
        if (groupLoan.getOutstandingBalance() <= 0) {
            throw new IllegalStateException("This group loan has no outstanding balance to write off.");
        }

        long writeOffAmount = groupLoan.getOutstandingBalance();
        long principalOutstanding = accountBalance(groupLoan.getReceivableAccountId());

        if (principalOutstanding > 0) {
            List<LedgerLine> lines = new ArrayList<>();
            lines.add(LedgerLine.debit(chart.badDebtExpense().getId(), principalOutstanding));
            lines.add(LedgerLine.credit(groupLoan.getReceivableAccountId(), principalOutstanding));

            ledger.post(EntryRequest.of(TransactionType.GROUP_LOAN_WRITE_OFF, lines)
                    .paymentMethod(PaymentMethod.CASH)
                    .recordedBy(writtenOffBy)
                    .recordedAt(Instant.now())
                    .description("Group loan write-off " + groupLoan.getLoanNumber()));
        }

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE group_loans SET status = ?, outstanding_balance = 0, written_off_at = ?,"
                     + " write_off_reason = ?, write_off_amount = ?, approved_by = ?, updated_at = ? WHERE id = ?")) {
            String now = Instant.now().toString();
            ps.setString(1, GroupLoanStatus.WRITTEN_OFF.value());
            ps.setString(2, now);
            ps.setString(3, reason);
            ps.setLong(4, writeOffAmount);
            ps.setString(5, writtenOffBy);
            ps.setString(6, now);
            ps.setString(7, groupLoanId);
            ps.executeUpdate();
        }

        outbox.enqueueIfHybrid("group_loan.write_off", new JSONObject()
                .put("group_loan_id", groupLoanId)
                .put("reason", reason));

        return findById(groupLoanId);
    }

    private List<LoanGroupMember> activeMembers(String loanGroupId) throws SQLException {
        return loanGroupService.findMembers(loanGroupId).stream()
                .filter(m -> "active".equals(m.getStatus()))
                .sorted((a, b) -> {
                    int byJoined = a.getJoinedAt().compareTo(b.getJoinedAt());
                    return byJoined != 0 ? byJoined : a.getId().compareTo(b.getId());
                })
                .toList();
    }

    private void insertRefinancedGroupLoan(Connection conn, String newGroupLoanId, String loanGroupId,
                                            String loanProductId, String agentId, String approvedBy,
                                            String receivableAccountId, String loanNumber, long principalAmount,
                                            String interestMethod, int interestRateBps, int termPeriodCount,
                                            String repaymentFrequency, long originationFeeAmount, int penaltyRateBps,
                                            int gracePeriodDays, long totalInterest, long totalRepayable,
                                            int memberCount, String previousGroupLoanId, long rolledOverAmount,
                                            String now) throws SQLException {
        String sql = "INSERT INTO group_loans (id, loan_group_id, loan_product_id, agent_id, approved_by,"
                + " receivable_account_id, loan_number, principal_amount, interest_method, interest_rate_bps,"
                + " term_period_count, repayment_frequency, origination_fee_amount, penalty_rate_bps,"
                + " grace_period_days, total_interest, total_repayable, outstanding_balance,"
                + " member_count_at_disbursement, status, previous_group_loan_id, rolled_over_amount, applied_at,"
                + " approved_at, disbursed_at, created_at, updated_at)"
                + " VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        try (PreparedStatement ps = conn.prepareStatement(sql)) {
            ps.setString(1, newGroupLoanId);
            ps.setString(2, loanGroupId);
            ps.setString(3, loanProductId);
            ps.setString(4, agentId);
            ps.setString(5, approvedBy);
            ps.setString(6, receivableAccountId);
            ps.setString(7, loanNumber);
            ps.setLong(8, principalAmount);
            ps.setString(9, interestMethod);
            ps.setInt(10, interestRateBps);
            ps.setInt(11, termPeriodCount);
            ps.setString(12, repaymentFrequency);
            ps.setLong(13, originationFeeAmount);
            ps.setInt(14, penaltyRateBps);
            ps.setInt(15, gracePeriodDays);
            ps.setLong(16, totalInterest);
            ps.setLong(17, totalRepayable);
            ps.setLong(18, totalRepayable);
            ps.setInt(19, memberCount);
            ps.setString(20, GroupLoanStatus.DISBURSED.value());
            ps.setString(21, previousGroupLoanId);
            ps.setLong(22, rolledOverAmount);
            ps.setString(23, now);
            ps.setString(24, now);
            ps.setString(25, now);
            ps.setString(26, now);
            ps.setString(27, now);
            ps.executeUpdate();
        }
    }

    private void insertRefinancedBorrowers(Connection conn, String newGroupLoanId, List<LoanGroupMember> members,
                                            long principalAmount) throws SQLException {
        int memberCount = members.size();
        long sharePerMember = principalAmount / memberCount;
        long shareRemainder = principalAmount - (sharePerMember * memberCount);

        for (int i = 0; i < members.size(); i++) {
            LoanGroupMember member = members.get(i);
            long share = sharePerMember + (i == memberCount - 1 ? shareRemainder : 0);

            String sql = "INSERT INTO group_loan_borrowers (id, group_loan_id, loan_group_member_id, customer_id,"
                    + " share_principal, share_outstanding, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                String now = Instant.now().toString();
                ps.setString(1, UUID.randomUUID().toString());
                ps.setString(2, newGroupLoanId);
                ps.setString(3, member.getId());
                ps.setString(4, member.getCustomerId());
                ps.setLong(5, share);
                ps.setLong(6, share);
                ps.setString(7, now);
                ps.setString(8, now);
                ps.executeUpdate();
            }
        }
    }

    private void insertRefinancedGroupInstallments(Connection conn, String newGroupLoanId,
                                                    List<ScheduledInstallment> schedule) throws SQLException {
        for (ScheduledInstallment installment : schedule) {
            String sql = "INSERT INTO group_loan_installments (id, group_loan_id, sequence, due_date, principal_due,"
                    + " interest_due, penalty_due, principal_paid, interest_paid, penalty_paid, status,"
                    + " created_at, updated_at) VALUES (?,?,?,?,?,?,0,0,0,0,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                String instNow = Instant.now().toString();
                ps.setString(1, UUID.randomUUID().toString());
                ps.setString(2, newGroupLoanId);
                ps.setInt(3, installment.sequence());
                ps.setString(4, installment.dueDate().format(DateTimeFormatter.ISO_LOCAL_DATE));
                ps.setLong(5, installment.principalDue());
                ps.setLong(6, installment.interestDue());
                ps.setString(7, InstallmentStatus.PENDING.value());
                ps.setString(8, instNow);
                ps.setString(9, instNow);
                ps.executeUpdate();
            }
        }
    }

    private void closeRefinancedGroupLoan(Connection conn, String oldGroupLoanId, String refinanceType, String reason,
                                           long refinanceAmount, String now) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement(
                "UPDATE group_loans SET status = ?, outstanding_balance = 0, refinanced_at = ?, refinance_type = ?,"
                + " refinance_reason = ?, refinance_amount = ?, updated_at = ? WHERE id = ?")) {
            ps.setString(1, GroupLoanStatus.REFINANCED.value());
            ps.setString(2, now);
            ps.setString(3, refinanceType);
            ps.setString(4, reason);
            ps.setLong(5, refinanceAmount);
            ps.setString(6, now);
            ps.setString(7, oldGroupLoanId);
            ps.executeUpdate();
        }
    }

    private long accountBalance(String accountId) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT balance FROM ledger_accounts WHERE id = ?")) {
            ps.setString(1, accountId);
            try (ResultSet rs = ps.executeQuery()) {
                rs.next();
                return rs.getLong("balance");
            }
        }
    }

    /**
     * Records one member's repayment against the group's SHARED outstanding
     * balance (not the borrower's own share) — under joint & several
     * liability, any member may pay down more than their own share to cover
     * a delinquent co-member. The borrower's own share_outstanding is
     * accountability bookkeeping only: it floors at 0 and is never
     * reallocated onto another member's share.
     */
    public GroupLoanRepaymentResult recordRepayment(String groupLoanBorrowerId, long amount, String recordedBy,
                                                      String clientReference, Instant recordedAt) throws SQLException {
        String effectiveClientReference = clientReference != null ? clientReference : UUID.randomUUID().toString();

        JournalEntry existingEntry = ledger.findByClientReference(effectiveClientReference);
        if (existingEntry != null) {
            GroupLoanRepayment existingRepayment = findRepaymentByClientReference(effectiveClientReference);
            GroupLoanBorrower existingBorrower = existingRepayment != null
                    ? findBorrowerById(existingRepayment.getGroupLoanBorrowerId()) : findBorrowerById(groupLoanBorrowerId);
            GroupLoan existingLoan = existingBorrower != null ? findById(existingBorrower.getGroupLoanId()) : null;
            return new GroupLoanRepaymentResult(existingEntry, existingLoan, existingBorrower, true);
        }

        GroupLoanBorrower borrower = findBorrowerById(groupLoanBorrowerId);
        if (borrower == null) {
            throw new IllegalArgumentException("Borrower not found.");
        }
        GroupLoan groupLoan = findById(borrower.getGroupLoanId());
        if (groupLoan == null || groupLoan.getStatus() != GroupLoanStatus.DISBURSED) {
            throw new IllegalStateException("Only disbursed group loans can receive repayments.");
        }
        if (amount <= 0 || amount > groupLoan.getOutstandingBalance()) {
            throw new IllegalArgumentException("Amount must be positive and cannot exceed the group loan's outstanding balance.");
        }

        Instant effectiveRecordedAt = recordedAt != null ? recordedAt : Instant.now();
        long[] applied = applyToInstallments(groupLoan.getId(), amount);
        long principalApplied = applied[0];
        long interestApplied = applied[1];
        long penaltyApplied = applied[2];

        List<LedgerLine> lines = new ArrayList<>();
        lines.add(LedgerLine.debit(chart.branchCash().getId(), amount));
        if (principalApplied > 0) {
            lines.add(LedgerLine.credit(groupLoan.getReceivableAccountId(), principalApplied));
        }
        if (interestApplied > 0) {
            lines.add(LedgerLine.credit(chart.loanInterestIncome().getId(), interestApplied));
        }
        if (penaltyApplied > 0) {
            lines.add(LedgerLine.credit(chart.loanPenaltyIncome().getId(), penaltyApplied));
        }

        JournalEntry entry = ledger.post(EntryRequest.of(TransactionType.GROUP_LOAN_REPAYMENT, lines)
                .paymentMethod(PaymentMethod.CASH)
                .recordedBy(recordedBy)
                .recordedAt(effectiveRecordedAt)
                .clientReference(effectiveClientReference)
                .description("Group loan repayment " + groupLoan.getLoanNumber()));

        String repaymentId = UUID.randomUUID().toString();
        long newBorrowerOutstanding = Math.max(0, borrower.getShareOutstanding() - amount);
        long newOutstanding = groupLoan.getOutstandingBalance() - amount;

        try (Connection conn = DatabaseConnection.getConnection()) {
            String now = Instant.now().toString();
            try (PreparedStatement ps = conn.prepareStatement(
                    "INSERT INTO group_loan_repayments (id, group_loan_id, group_loan_borrower_id, journal_entry_id,"
                    + " recorded_by, amount, recorded_at, client_reference, created_at, updated_at)"
                    + " VALUES (?,?,?,?,?,?,?,?,?,?)")) {
                ps.setString(1, repaymentId);
                ps.setString(2, groupLoan.getId());
                ps.setString(3, borrower.getId());
                ps.setString(4, entry.getId());
                ps.setString(5, recordedBy);
                ps.setLong(6, amount);
                ps.setString(7, effectiveRecordedAt.toString());
                ps.setString(8, effectiveClientReference);
                ps.setString(9, now);
                ps.setString(10, now);
                ps.executeUpdate();
            }

            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE group_loan_borrowers SET share_outstanding = ?, updated_at = ? WHERE id = ?")) {
                ps.setLong(1, newBorrowerOutstanding);
                ps.setString(2, now);
                ps.setString(3, borrower.getId());
                ps.executeUpdate();
            }

            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE group_loans SET outstanding_balance = ?, status = ?, closed_at = ?, updated_at = ? WHERE id = ?")) {
                ps.setLong(1, newOutstanding);
                ps.setString(2, newOutstanding <= 0 ? GroupLoanStatus.CLOSED.value() : groupLoan.getStatus().value());
                ps.setString(3, newOutstanding <= 0 ? now : null);
                ps.setString(4, now);
                ps.setString(5, groupLoan.getId());
                ps.executeUpdate();
            }
        }

        outbox.enqueueIfHybrid("group_loan.repayment.record", new JSONObject()
                .put("group_loan_id", groupLoan.getId())
                .put("group_loan_borrower_id", borrower.getId())
                .put("amount", amount)
                .put("client_reference", effectiveClientReference)
                .put("recorded_at", effectiveRecordedAt.toString()));

        return new GroupLoanRepaymentResult(entry, findById(groupLoan.getId()), findBorrowerById(borrower.getId()), false);
    }

    /**
     * Applies a repayment across the group's outstanding installments
     * oldest-first — penalty, then interest, then principal within each.
     * Identical algorithm to LoanService.applyToInstallments, retargeted at
     * group_loan_installments.
     *
     * @return [principalApplied, interestApplied, penaltyApplied]
     */
    private long[] applyToInstallments(String groupLoanId, long amount) throws SQLException {
        long remaining = amount;
        long principalApplied = 0;
        long interestApplied = 0;
        long penaltyApplied = 0;

        List<GroupLoanInstallment> installments = findInstallments(groupLoanId).stream()
                .filter(i -> i.getStatus() == InstallmentStatus.PENDING
                        || i.getStatus() == InstallmentStatus.PARTIALLY_PAID
                        || i.getStatus() == InstallmentStatus.OVERDUE)
                .toList();

        for (GroupLoanInstallment installment : installments) {
            if (remaining <= 0) {
                break;
            }

            long installmentTotal = Math.min(installment.remaining(), remaining);
            if (installmentTotal <= 0) {
                continue;
            }

            long penaltyPortion = Math.min(installment.remainingPenalty(), installmentTotal);
            long interestPortion = Math.min(installment.remainingInterest(), installmentTotal - penaltyPortion);
            long principalPortion = Math.min(installment.remainingPrincipal(),
                    installmentTotal - penaltyPortion - interestPortion);

            boolean wasOverdue = installment.getStatus() == InstallmentStatus.OVERDUE;
            long newPenaltyPaid = installment.getPenaltyPaid() + penaltyPortion;
            long newInterestPaid = installment.getInterestPaid() + interestPortion;
            long newPrincipalPaid = installment.getPrincipalPaid() + principalPortion;
            long newAmountPaid = newPenaltyPaid + newInterestPaid + newPrincipalPaid;

            InstallmentStatus newStatus;
            Instant paidAt = null;
            if (newAmountPaid >= installment.totalDue()) {
                newStatus = InstallmentStatus.PAID;
                paidAt = Instant.now();
            } else if (wasOverdue) {
                newStatus = InstallmentStatus.OVERDUE;
            } else {
                newStatus = InstallmentStatus.PARTIALLY_PAID;
            }

            try (Connection conn = DatabaseConnection.getConnection();
                 PreparedStatement ps = conn.prepareStatement(
                         "UPDATE group_loan_installments SET penalty_paid = ?, interest_paid = ?, principal_paid = ?,"
                         + " status = ?, paid_at = ?, updated_at = ? WHERE id = ?")) {
                ps.setLong(1, newPenaltyPaid);
                ps.setLong(2, newInterestPaid);
                ps.setLong(3, newPrincipalPaid);
                ps.setString(4, newStatus.value());
                ps.setString(5, paidAt != null ? paidAt.toString() : null);
                ps.setString(6, Instant.now().toString());
                ps.setString(7, installment.getId());
                ps.executeUpdate();
            }

            penaltyApplied += penaltyPortion;
            interestApplied += interestPortion;
            principalApplied += principalPortion;
            remaining -= installmentTotal;
        }

        return new long[] {principalApplied, interestApplied, penaltyApplied};
    }

    private GroupLoan requireStatus(String groupLoanId, GroupLoanStatus expected, String action) throws SQLException {
        GroupLoan groupLoan = findById(groupLoanId);
        if (groupLoan == null || groupLoan.getStatus() != expected) {
            throw new IllegalStateException("Only " + expected.value() + " group loans can be " + action + ".");
        }
        return groupLoan;
    }

    /** G7 numbering: GL-{sequence} — GL distinguishes from an individual loan's LN prefix. */
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
        groupLoan.setProduct(productService.findById(groupLoan.getLoanProductId()));
        groupLoan.setBorrowers(findBorrowers(id));
        groupLoan.setInstallments(findInstallments(id));
        return groupLoan;
    }

    public List<GroupLoan> findAll() throws SQLException {
        List<GroupLoan> groupLoans = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM group_loans ORDER BY applied_at DESC");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                groupLoans.add(map(rs));
            }
        }
        for (GroupLoan groupLoan : groupLoans) {
            groupLoan.setLoanGroup(loanGroupService.findById(groupLoan.getLoanGroupId()));
        }
        return groupLoans;
    }

    public List<GroupLoanBorrower> findBorrowers(String groupLoanId) throws SQLException {
        List<GroupLoanBorrower> borrowers = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM group_loan_borrowers WHERE group_loan_id = ? ORDER BY created_at")) {
            ps.setString(1, groupLoanId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    borrowers.add(mapBorrower(rs));
                }
            }
        }
        for (GroupLoanBorrower borrower : borrowers) {
            borrower.setCustomer(customerService.findById(borrower.getCustomerId()));
        }
        return borrowers;
    }

    public GroupLoanBorrower findBorrowerById(String id) throws SQLException {
        GroupLoanBorrower borrower;
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM group_loan_borrowers WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                if (!rs.next()) {
                    return null;
                }
                borrower = mapBorrower(rs);
            }
        }
        borrower.setCustomer(customerService.findById(borrower.getCustomerId()));
        return borrower;
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
        groupLoan.setLoanProductId(rs.getString("loan_product_id"));
        groupLoan.setAgentId(rs.getString("agent_id"));
        groupLoan.setApprovedBy(rs.getString("approved_by"));
        groupLoan.setReceivableAccountId(rs.getString("receivable_account_id"));
        groupLoan.setLoanNumber(rs.getString("loan_number"));
        groupLoan.setPrincipalAmount(rs.getLong("principal_amount"));
        groupLoan.setInterestMethod(enums.InterestMethod.fromValue(rs.getString("interest_method")));
        groupLoan.setInterestRateBps(rs.getInt("interest_rate_bps"));
        groupLoan.setTermPeriodCount(rs.getInt("term_period_count"));
        groupLoan.setRepaymentFrequency(enums.LoanFrequency.fromValue(rs.getString("repayment_frequency")));
        groupLoan.setOriginationFeeAmount(rs.getLong("origination_fee_amount"));
        groupLoan.setPenaltyRateBps(rs.getInt("penalty_rate_bps"));
        groupLoan.setGracePeriodDays(rs.getInt("grace_period_days"));
        groupLoan.setTotalInterest(rs.getLong("total_interest"));
        groupLoan.setTotalRepayable(rs.getLong("total_repayable"));
        groupLoan.setOutstandingBalance(rs.getLong("outstanding_balance"));
        int memberCount = rs.getInt("member_count_at_disbursement");
        groupLoan.setMemberCountAtDisbursement(rs.wasNull() ? null : memberCount);
        groupLoan.setStatus(GroupLoanStatus.fromValue(rs.getString("status")));
        groupLoan.setRejectionReason(rs.getString("rejection_reason"));
        groupLoan.setNotes(rs.getString("notes"));
        groupLoan.setClientReference(rs.getString("client_reference"));
        groupLoan.setAppliedAt(Instant.parse(rs.getString("applied_at")));
        String approvedAt = rs.getString("approved_at");
        groupLoan.setApprovedAt(approvedAt != null ? Instant.parse(approvedAt) : null);
        String disbursedAt = rs.getString("disbursed_at");
        groupLoan.setDisbursedAt(disbursedAt != null ? Instant.parse(disbursedAt) : null);
        String closedAt = rs.getString("closed_at");
        groupLoan.setClosedAt(closedAt != null ? Instant.parse(closedAt) : null);
        groupLoan.setPreviousGroupLoanId(rs.getString("previous_group_loan_id"));
        groupLoan.setRolledOverAmount(rs.getLong("rolled_over_amount"));
        String refinancedAt = rs.getString("refinanced_at");
        groupLoan.setRefinancedAt(refinancedAt != null ? Instant.parse(refinancedAt) : null);
        groupLoan.setRefinanceType(rs.getString("refinance_type"));
        groupLoan.setRefinanceReason(rs.getString("refinance_reason"));
        long refinanceAmount = rs.getLong("refinance_amount");
        groupLoan.setRefinanceAmount(rs.wasNull() ? null : refinanceAmount);
        String writtenOffAt = rs.getString("written_off_at");
        groupLoan.setWrittenOffAt(writtenOffAt != null ? Instant.parse(writtenOffAt) : null);
        groupLoan.setWriteOffReason(rs.getString("write_off_reason"));
        long writeOffAmount = rs.getLong("write_off_amount");
        groupLoan.setWriteOffAmount(rs.wasNull() ? null : writeOffAmount);
        return groupLoan;
    }

    private GroupLoanBorrower mapBorrower(ResultSet rs) throws SQLException {
        GroupLoanBorrower borrower = new GroupLoanBorrower();
        borrower.setId(rs.getString("id"));
        borrower.setGroupLoanId(rs.getString("group_loan_id"));
        borrower.setLoanGroupMemberId(rs.getString("loan_group_member_id"));
        borrower.setCustomerId(rs.getString("customer_id"));
        borrower.setSharePrincipal(rs.getLong("share_principal"));
        borrower.setShareOutstanding(rs.getLong("share_outstanding"));
        return borrower;
    }

    private GroupLoanInstallment mapInstallment(ResultSet rs) throws SQLException {
        GroupLoanInstallment installment = new GroupLoanInstallment();
        installment.setId(rs.getString("id"));
        installment.setGroupLoanId(rs.getString("group_loan_id"));
        installment.setSequence(rs.getInt("sequence"));
        installment.setDueDate(LocalDate.parse(rs.getString("due_date")));
        installment.setPrincipalDue(rs.getLong("principal_due"));
        installment.setInterestDue(rs.getLong("interest_due"));
        installment.setPenaltyDue(rs.getLong("penalty_due"));
        installment.setPrincipalPaid(rs.getLong("principal_paid"));
        installment.setInterestPaid(rs.getLong("interest_paid"));
        installment.setPenaltyPaid(rs.getLong("penalty_paid"));
        installment.setStatus(InstallmentStatus.fromValue(rs.getString("status")));
        String paidAt = rs.getString("paid_at");
        installment.setPaidAt(paidAt != null ? Instant.parse(paidAt) : null);
        return installment;
    }

    private GroupLoanRepayment mapRepayment(ResultSet rs) throws SQLException {
        GroupLoanRepayment repayment = new GroupLoanRepayment();
        repayment.setId(rs.getString("id"));
        repayment.setGroupLoanId(rs.getString("group_loan_id"));
        repayment.setGroupLoanBorrowerId(rs.getString("group_loan_borrower_id"));
        repayment.setJournalEntryId(rs.getString("journal_entry_id"));
        repayment.setRecordedBy(rs.getString("recorded_by"));
        repayment.setAmount(rs.getLong("amount"));
        repayment.setRecordedAt(Instant.parse(rs.getString("recorded_at")));
        repayment.setClientReference(rs.getString("client_reference"));
        return repayment;
    }
}
