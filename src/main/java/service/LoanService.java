package service;

import db.DatabaseConnection;
import enums.InstallmentStatus;
import enums.LoanStatus;
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
import models.JournalEntry;
import models.Loan;
import models.LoanInstallment;
import models.LoanProduct;
import models.SavingsAccount;
import org.json.JSONObject;

/**
 * Loan lifecycle: apply → approve/reject → disburse → repayments. A
 * disciplined, version-stamped ({@link LedgerService#ENGINE_VERSION}) parity
 * port of the backend's {@code App\Actions\Loans\*Action} classes — this is
 * the standalone-mode operations engine's loan module (AD-5). Approval and
 * disbursement run against the local engine in both standalone and hybrid
 * mode (company_admin/branch_manager decide offline, same as collections and
 * withdrawals already do here); hybrid mode also pushes the outcome through
 * the outbox afterward as an audit trail.
 */
public class LoanService {

    private final LedgerService ledger = new LedgerService();
    private final ChartOfAccounts chart = new ChartOfAccounts();
    private final ScheduleGenerator scheduleGenerator = new ScheduleGenerator();
    private final SavingsAccountService accountService = new SavingsAccountService();
    private final CustomerService customerService = new CustomerService();
    private final LoanProductService productService = new LoanProductService();
    private final OutboxService outbox = new OutboxService();

    public Loan apply(String agentId, String customerId, String productId, long requestedAmount,
                       String savingsAccountId, String guarantorName, String guarantorPhone, String notes,
                       String clientReference) throws SQLException {
        String effectiveClientReference = clientReference != null ? clientReference : UUID.randomUUID().toString();

        Loan existing = findByClientReference(effectiveClientReference);
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
        if (savingsAccountId != null) {
            SavingsAccount account = accountService.findById(savingsAccountId);
            if (account == null || !account.getCustomerId().equals(customerId)) {
                throw new IllegalArgumentException("This account does not belong to the customer.");
            }
        }

        // The local id doubles as client_reference (see SavingsAccountService.open
        // for why): the backend's ApplyForLoanAction uses it as the row's own id,
        // so later ops in this loan's lifecycle (approve/disburse/repayment)
        // resolve to the same record once synced.
        String id = effectiveClientReference;
        String now = Instant.now().toString();
        String loanNumber;

        try (Connection conn = DatabaseConnection.getConnection()) {
            loanNumber = nextLoanNumber(conn);

            String sql = "INSERT INTO loans (id, customer_id, loan_product_id, savings_account_id, agent_id,"
                    + " loan_number, principal_amount, interest_method, interest_rate_bps, term_period_count,"
                    + " repayment_frequency, origination_fee_amount, penalty_rate_bps, grace_period_days,"
                    + " total_interest, total_repayable, outstanding_balance, status, guarantor_name,"
                    + " guarantor_phone, notes, client_reference, applied_at, created_at, updated_at)"
                    + " VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,0,?,?,?,?,?,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, customerId);
                ps.setString(3, productId);
                ps.setString(4, savingsAccountId);
                ps.setString(5, agentId);
                ps.setString(6, loanNumber);
                ps.setLong(7, requestedAmount);
                ps.setString(8, product.getInterestMethod().value());
                ps.setInt(9, product.getInterestRateBps());
                ps.setInt(10, product.getTermPeriodCount());
                ps.setString(11, product.getRepaymentFrequency().value());
                ps.setLong(12, product.getOriginationFeeAmount());
                ps.setInt(13, product.getPenaltyRateBps());
                ps.setInt(14, product.getGracePeriodDays());
                ps.setString(15, LoanStatus.APPLIED.value());
                ps.setString(16, guarantorName);
                ps.setString(17, guarantorPhone);
                ps.setString(18, notes);
                ps.setString(19, effectiveClientReference);
                ps.setString(20, now);
                ps.setString(21, now);
                ps.setString(22, now);
                ps.executeUpdate();
            }
        }

        outbox.enqueueIfHybrid("loan.apply", new JSONObject()
                .put("customer_id", customerId)
                .put("loan_product_id", productId)
                .put("amount", requestedAmount)
                .put("savings_account_id", savingsAccountId)
                .put("guarantor_name", guarantorName)
                .put("guarantor_phone", guarantorPhone)
                .put("notes", notes)
                .put("client_reference", effectiveClientReference));

        return findById(id);
    }

    public Loan approve(String loanId, String approvedBy) throws SQLException {
        Loan loan = requireStatus(loanId, LoanStatus.APPLIED, "approved");

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE loans SET status = ?, approved_by = ?, approved_at = ?, updated_at = ? WHERE id = ?")) {
            ps.setString(1, LoanStatus.APPROVED.value());
            ps.setString(2, approvedBy);
            ps.setString(3, Instant.now().toString());
            ps.setString(4, Instant.now().toString());
            ps.setString(5, loanId);
            ps.executeUpdate();
        }

        outbox.enqueueIfHybrid("loan.approve", new JSONObject().put("loan_id", loanId));

        return findById(loanId);
    }

    public Loan reject(String loanId, String rejectedBy, String reason) throws SQLException {
        requireStatus(loanId, LoanStatus.APPLIED, "rejected");

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE loans SET status = ?, approved_by = ?, rejection_reason = ?, updated_at = ? WHERE id = ?")) {
            ps.setString(1, LoanStatus.REJECTED.value());
            ps.setString(2, rejectedBy);
            ps.setString(3, reason);
            ps.setString(4, Instant.now().toString());
            ps.setString(5, loanId);
            ps.executeUpdate();
        }

        outbox.enqueueIfHybrid("loan.reject", new JSONObject().put("loan_id", loanId).put("reason", reason));

        return findById(loanId);
    }

    public Loan disburse(String loanId, String disbursedBy) throws SQLException {
        Loan loan = requireStatus(loanId, LoanStatus.APPROVED, "disbursed");

        Instant disbursedAt = Instant.now();
        LocalDate disbursedDate = LocalDate.ofInstant(disbursedAt, ZoneId.systemDefault());

        List<ScheduledInstallment> schedule = scheduleGenerator.generate(
                loan.getPrincipalAmount(), loan.getInterestRateBps(), loan.getTermPeriodCount(),
                loan.getInterestMethod(), loan.getRepaymentFrequency(), disbursedDate);

        long totalInterest = schedule.stream().mapToLong(ScheduledInstallment::interestDue).sum();
        long totalRepayable = loan.getPrincipalAmount() + totalInterest;
        long netCash = loan.getPrincipalAmount() - loan.getOriginationFeeAmount();

        String receivableAccountId = chart.loanReceivable(loan.getId(), loan.getLoanNumber()).getId();

        List<LedgerLine> lines = new ArrayList<>();
        lines.add(LedgerLine.debit(receivableAccountId, loan.getPrincipalAmount()));
        lines.add(LedgerLine.credit(chart.branchCash().getId(), netCash));
        if (loan.getOriginationFeeAmount() > 0) {
            lines.add(LedgerLine.credit(chart.loanFeeIncome().getId(), loan.getOriginationFeeAmount()));
        }

        ledger.post(EntryRequest.of(TransactionType.DISBURSEMENT, lines)
                .paymentMethod(PaymentMethod.CASH)
                .recordedBy(disbursedBy)
                .recordedAt(disbursedAt)
                .description("Loan disbursement " + loan.getLoanNumber()));

        try (Connection conn = DatabaseConnection.getConnection()) {
            for (ScheduledInstallment installment : schedule) {
                String sql = "INSERT INTO loan_installments (id, loan_id, sequence, due_date, principal_due,"
                        + " interest_due, penalty_due, principal_paid, interest_paid, penalty_paid, status,"
                        + " created_at, updated_at) VALUES (?,?,?,?,?,?,0,0,0,0,?,?,?)";
                try (PreparedStatement ps = conn.prepareStatement(sql)) {
                    String now = Instant.now().toString();
                    ps.setString(1, UUID.randomUUID().toString());
                    ps.setString(2, loan.getId());
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
                    "UPDATE loans SET receivable_account_id = ?, total_interest = ?, total_repayable = ?,"
                    + " outstanding_balance = ?, status = ?, disbursed_at = ?, updated_at = ? WHERE id = ?")) {
                ps.setString(1, receivableAccountId);
                ps.setLong(2, totalInterest);
                ps.setLong(3, totalRepayable);
                ps.setLong(4, totalRepayable);
                ps.setString(5, LoanStatus.DISBURSED.value());
                ps.setString(6, disbursedAt.toString());
                ps.setString(7, Instant.now().toString());
                ps.setString(8, loan.getId());
                ps.executeUpdate();
            }
        }

        outbox.enqueueIfHybrid("loan.disburse", new JSONObject().put("loan_id", loanId));

        return findById(loanId);
    }

    public LoanRepaymentResult recordRepayment(String loanId, long amount, String recordedBy,
                                                String clientReference, Instant recordedAt) throws SQLException {
        String effectiveClientReference = clientReference != null ? clientReference : UUID.randomUUID().toString();

        JournalEntry existingEntry = ledger.findByClientReference(effectiveClientReference);
        if (existingEntry != null) {
            return new LoanRepaymentResult(existingEntry, findById(loanId), true);
        }

        Loan loan = findById(loanId);
        if (loan == null || loan.getStatus() != LoanStatus.DISBURSED) {
            throw new IllegalStateException("Only disbursed loans can receive repayments.");
        }
        if (amount <= 0 || amount > loan.getOutstandingBalance()) {
            throw new IllegalArgumentException("Amount must be positive and cannot exceed the outstanding balance.");
        }

        Instant effectiveRecordedAt = recordedAt != null ? recordedAt : Instant.now();
        long[] applied = applyToInstallments(loanId, amount);
        long principalApplied = applied[0];
        long interestApplied = applied[1];
        long penaltyApplied = applied[2];

        List<LedgerLine> lines = new ArrayList<>();
        lines.add(LedgerLine.debit(chart.branchCash().getId(), amount));
        if (principalApplied > 0) {
            lines.add(LedgerLine.credit(loan.getReceivableAccountId(), principalApplied));
        }
        if (interestApplied > 0) {
            lines.add(LedgerLine.credit(chart.loanInterestIncome().getId(), interestApplied));
        }
        if (penaltyApplied > 0) {
            lines.add(LedgerLine.credit(chart.loanPenaltyIncome().getId(), penaltyApplied));
        }

        JournalEntry entry = ledger.post(EntryRequest.of(TransactionType.REPAYMENT, lines)
                .paymentMethod(PaymentMethod.CASH)
                .recordedBy(recordedBy)
                .recordedAt(effectiveRecordedAt)
                .clientReference(effectiveClientReference)
                .description("Loan repayment " + loan.getLoanNumber()));

        long newOutstanding = loan.getOutstandingBalance() - amount;
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE loans SET outstanding_balance = ?, status = ?, closed_at = ?, updated_at = ? WHERE id = ?")) {
            ps.setLong(1, newOutstanding);
            ps.setString(2, newOutstanding <= 0 ? LoanStatus.CLOSED.value() : loan.getStatus().value());
            ps.setString(3, newOutstanding <= 0 ? Instant.now().toString() : null);
            ps.setString(4, Instant.now().toString());
            ps.setString(5, loanId);
            ps.executeUpdate();
        }

        outbox.enqueueIfHybrid("loan.repayment.record", new JSONObject()
                .put("loan_id", loanId)
                .put("amount", amount)
                .put("client_reference", effectiveClientReference)
                .put("recorded_at", effectiveRecordedAt.toString()));

        return new LoanRepaymentResult(entry, findById(loanId), false);
    }

    /**
     * Applies a repayment across outstanding installments oldest-first —
     * penalty, then interest, then principal within each installment.
     *
     * @return [principalApplied, interestApplied, penaltyApplied]
     */
    private long[] applyToInstallments(String loanId, long amount) throws SQLException {
        long remaining = amount;
        long principalApplied = 0;
        long interestApplied = 0;
        long penaltyApplied = 0;

        List<LoanInstallment> installments = findInstallments(loanId).stream()
                .filter(i -> i.getStatus() == InstallmentStatus.PENDING
                        || i.getStatus() == InstallmentStatus.PARTIALLY_PAID
                        || i.getStatus() == InstallmentStatus.OVERDUE)
                .toList();

        for (LoanInstallment installment : installments) {
            if (remaining <= 0) {
                break;
            }

            long installmentTotal = Math.min(installment.remaining(), remaining);
            if (installmentTotal <= 0) {
                continue;
            }

            // Penalty first (it's the punitive charge for lateness), then
            // interest, then principal — matches totalDue()'s composition so
            // the three portions always sum to exactly installmentTotal.
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
                // Stays visibly overdue through a partial payment rather than
                // reverting to PARTIALLY_PAID — it's still late until settled.
                newStatus = InstallmentStatus.OVERDUE;
            } else {
                newStatus = InstallmentStatus.PARTIALLY_PAID;
            }

            try (Connection conn = DatabaseConnection.getConnection();
                 PreparedStatement ps = conn.prepareStatement(
                         "UPDATE loan_installments SET penalty_paid = ?, interest_paid = ?, principal_paid = ?,"
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

    private Loan requireStatus(String loanId, LoanStatus expected, String action) throws SQLException {
        Loan loan = findById(loanId);
        if (loan == null || loan.getStatus() != expected) {
            throw new IllegalStateException("Only " + expected.value() + " loans can be " + action + ".");
        }
        return loan;
    }

    private String nextLoanNumber(Connection conn) throws SQLException {
        int sequence;
        try (PreparedStatement ps = conn.prepareStatement("SELECT COUNT(*) FROM loans");
             ResultSet rs = ps.executeQuery()) {
            rs.next();
            sequence = rs.getInt(1) + 1;
        }

        String number;
        do {
            number = "LN-" + String.format("%05d", sequence);
            sequence++;
        } while (numberExists(conn, number));

        return number;
    }

    private boolean numberExists(Connection conn, String number) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement("SELECT 1 FROM loans WHERE loan_number = ?")) {
            ps.setString(1, number);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next();
            }
        }
    }

    public Loan findById(String id) throws SQLException {
        Loan loan;
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM loans WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                if (!rs.next()) {
                    return null;
                }
                loan = map(rs);
            }
        }

        loan.setCustomer(customerService.findById(loan.getCustomerId()));
        loan.setProduct(productService.findById(loan.getLoanProductId()));
        loan.setInstallments(findInstallments(id));
        return loan;
    }

    public List<Loan> findAll() throws SQLException {
        List<Loan> loans = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM loans ORDER BY applied_at DESC");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                loans.add(map(rs));
            }
        }
        for (Loan loan : loans) {
            loan.setCustomer(customerService.findById(loan.getCustomerId()));
        }
        return loans;
    }

    public List<LoanInstallment> findInstallments(String loanId) throws SQLException {
        List<LoanInstallment> installments = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM loan_installments WHERE loan_id = ? ORDER BY sequence")) {
            ps.setString(1, loanId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    installments.add(mapInstallment(rs));
                }
            }
        }
        return installments;
    }

    private Loan findByClientReference(String clientReference) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM loans WHERE client_reference = ?")) {
            ps.setString(1, clientReference);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? map(rs) : null;
            }
        }
    }

    private Loan map(ResultSet rs) throws SQLException {
        Loan loan = new Loan();
        loan.setId(rs.getString("id"));
        loan.setCustomerId(rs.getString("customer_id"));
        loan.setLoanProductId(rs.getString("loan_product_id"));
        loan.setSavingsAccountId(rs.getString("savings_account_id"));
        loan.setAgentId(rs.getString("agent_id"));
        loan.setApprovedBy(rs.getString("approved_by"));
        loan.setReceivableAccountId(rs.getString("receivable_account_id"));
        loan.setLoanNumber(rs.getString("loan_number"));
        loan.setPrincipalAmount(rs.getLong("principal_amount"));
        loan.setInterestMethod(enums.InterestMethod.fromValue(rs.getString("interest_method")));
        loan.setInterestRateBps(rs.getInt("interest_rate_bps"));
        loan.setTermPeriodCount(rs.getInt("term_period_count"));
        loan.setRepaymentFrequency(enums.LoanFrequency.fromValue(rs.getString("repayment_frequency")));
        loan.setOriginationFeeAmount(rs.getLong("origination_fee_amount"));
        loan.setPenaltyRateBps(rs.getInt("penalty_rate_bps"));
        loan.setGracePeriodDays(rs.getInt("grace_period_days"));
        loan.setTotalInterest(rs.getLong("total_interest"));
        loan.setTotalRepayable(rs.getLong("total_repayable"));
        loan.setOutstandingBalance(rs.getLong("outstanding_balance"));
        loan.setStatus(LoanStatus.fromValue(rs.getString("status")));
        loan.setGuarantorName(rs.getString("guarantor_name"));
        loan.setGuarantorPhone(rs.getString("guarantor_phone"));
        loan.setRejectionReason(rs.getString("rejection_reason"));
        loan.setNotes(rs.getString("notes"));
        loan.setClientReference(rs.getString("client_reference"));
        loan.setAppliedAt(Instant.parse(rs.getString("applied_at")));
        String approvedAt = rs.getString("approved_at");
        loan.setApprovedAt(approvedAt != null ? Instant.parse(approvedAt) : null);
        String disbursedAt = rs.getString("disbursed_at");
        loan.setDisbursedAt(disbursedAt != null ? Instant.parse(disbursedAt) : null);
        String closedAt = rs.getString("closed_at");
        loan.setClosedAt(closedAt != null ? Instant.parse(closedAt) : null);
        return loan;
    }

    private LoanInstallment mapInstallment(ResultSet rs) throws SQLException {
        LoanInstallment installment = new LoanInstallment();
        installment.setId(rs.getString("id"));
        installment.setLoanId(rs.getString("loan_id"));
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
}
