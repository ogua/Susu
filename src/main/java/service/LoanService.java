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
import java.sql.Types;
import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.Comparator;
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
        return apply(agentId, customerId, productId, requestedAmount, savingsAccountId, guarantorName,
                guarantorPhone, notes, clientReference, LoanApplicationDetails.none());
    }

    /**
     * Applies with the application's optional details (term overrides,
     * itemised charges, collateral, guarantors, first repayment date) — mirrors
     * the backend's ApplyForLoanAction with a LoanApplicationDetails. Charges
     * sum to the origination fee; with none itemised, the product fee becomes
     * one "Processing fee" charge. The legacy guarantor columns fall back to
     * the first itemised guarantor.
     */
    public Loan apply(String agentId, String customerId, String productId, long requestedAmount,
                       String savingsAccountId, String guarantorName, String guarantorPhone, String notes,
                       String clientReference, LoanApplicationDetails details) throws SQLException {
        LoanApplicationDetails effectiveDetails = details != null ? details : LoanApplicationDetails.none();
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

        for (LoanApplicationDetails.Guarantor guarantor : effectiveDetails.guarantors()) {
            if (customerId.equals(guarantor.customerId())) {
                throw new IllegalArgumentException("A customer cannot guarantee their own loan.");
            }
        }

        List<LoanApplicationDetails.Charge> charges = effectiveDetails.charges() != null
                ? effectiveDetails.charges()
                : (product.getOriginationFeeAmount() > 0
                        ? List.of(new LoanApplicationDetails.Charge("Processing fee", product.getOriginationFeeAmount()))
                        : List.of());
        long originationFee = charges.stream().mapToLong(LoanApplicationDetails.Charge::amount).sum();
        LoanApplicationDetails.Guarantor firstGuarantor = effectiveDetails.guarantors().isEmpty()
                ? null : effectiveDetails.guarantors().get(0);
        String effectiveGuarantorName = guarantorName != null ? guarantorName : (firstGuarantor != null ? firstGuarantor.name() : null);
        String effectiveGuarantorPhone = guarantorPhone != null ? guarantorPhone : (firstGuarantor != null ? firstGuarantor.phone() : null);

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
                    + " guarantor_phone, notes, client_reference, applied_at, created_at, updated_at,"
                    + " purpose, first_repayment_date)"
                    + " VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,0,?,?,?,?,?,?,?,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, customerId);
                ps.setString(3, productId);
                ps.setString(4, savingsAccountId);
                ps.setString(5, agentId);
                ps.setString(6, loanNumber);
                ps.setLong(7, requestedAmount);
                ps.setString(8, (effectiveDetails.interestMethod() != null ? effectiveDetails.interestMethod() : product.getInterestMethod()).value());
                ps.setInt(9, effectiveDetails.interestRateBps() != null ? effectiveDetails.interestRateBps() : product.getInterestRateBps());
                ps.setInt(10, effectiveDetails.termPeriodCount() != null ? effectiveDetails.termPeriodCount() : product.getTermPeriodCount());
                ps.setString(11, (effectiveDetails.repaymentFrequency() != null ? effectiveDetails.repaymentFrequency() : product.getRepaymentFrequency()).value());
                ps.setLong(12, originationFee);
                ps.setInt(13, product.getPenaltyRateBps());
                ps.setInt(14, effectiveDetails.gracePeriodDays() != null ? effectiveDetails.gracePeriodDays() : product.getGracePeriodDays());
                ps.setString(15, LoanStatus.APPLIED.value());
                ps.setString(16, effectiveGuarantorName);
                ps.setString(17, effectiveGuarantorPhone);
                ps.setString(18, notes);
                ps.setString(19, effectiveClientReference);
                ps.setString(20, now);
                ps.setString(21, now);
                ps.setString(22, now);
                ps.setString(23, effectiveDetails.purpose());
                ps.setString(24, effectiveDetails.firstRepaymentDate() != null ? effectiveDetails.firstRepaymentDate().toString() : null);
                ps.executeUpdate();
            }

            insertApplicationDetails(conn, id, charges, effectiveDetails, now);
        }

        JSONObject payload = new JSONObject()
                .put("customer_id", customerId)
                .put("loan_product_id", productId)
                .put("amount", requestedAmount)
                .put("savings_account_id", savingsAccountId)
                .put("guarantor_name", guarantorName)
                .put("guarantor_phone", guarantorPhone)
                .put("notes", notes)
                .put("client_reference", effectiveClientReference);
        effectiveDetails.writeTo(payload);
        outbox.enqueueIfHybrid("loan.apply", payload);

        return findById(id);
    }

    /** Runs on the caller's open connection — never opens another (single-connection SQLite pool). */
    private void insertApplicationDetails(Connection conn, String loanId, List<LoanApplicationDetails.Charge> charges,
                                          LoanApplicationDetails details, String now) throws SQLException {
        for (LoanApplicationDetails.Charge charge : charges) {
            if (charge.amount() <= 0) {
                continue;
            }
            try (PreparedStatement ps = conn.prepareStatement(
                    "INSERT INTO loan_charges (id, loan_id, name, amount, created_at, updated_at) VALUES (?,?,?,?,?,?)")) {
                ps.setString(1, UUID.randomUUID().toString());
                ps.setString(2, loanId);
                ps.setString(3, charge.name());
                ps.setLong(4, charge.amount());
                ps.setString(5, now);
                ps.setString(6, now);
                ps.executeUpdate();
            }
        }
        for (LoanApplicationDetails.Collateral collateral : details.collaterals()) {
            try (PreparedStatement ps = conn.prepareStatement(
                    "INSERT INTO loan_collaterals (id, loan_id, type, description, estimated_value, serial_number, notes,"
                    + " created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)")) {
                ps.setString(1, UUID.randomUUID().toString());
                ps.setString(2, loanId);
                ps.setString(3, collateral.type());
                ps.setString(4, collateral.description());
                ps.setLong(5, collateral.estimatedValue());
                ps.setString(6, collateral.serialNumber());
                ps.setString(7, collateral.notes());
                ps.setString(8, now);
                ps.setString(9, now);
                ps.executeUpdate();
            }
        }
        for (LoanApplicationDetails.Guarantor guarantor : details.guarantors()) {
            try (PreparedStatement ps = conn.prepareStatement(
                    "INSERT INTO loan_guarantors (id, loan_id, customer_id, name, phone, relationship, address,"
                    + " guaranteed_amount, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)")) {
                ps.setString(1, UUID.randomUUID().toString());
                ps.setString(2, loanId);
                ps.setString(3, guarantor.customerId());
                ps.setString(4, guarantor.name());
                ps.setString(5, guarantor.phone());
                ps.setString(6, guarantor.relationship());
                ps.setString(7, guarantor.address());
                if (guarantor.guaranteedAmount() != null) {
                    ps.setLong(8, guarantor.guaranteedAmount());
                } else {
                    ps.setNull(8, Types.INTEGER);
                }
                ps.setString(9, now);
                ps.setString(10, now);
                ps.executeUpdate();
            }
        }
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
                loan.getInterestMethod(), loan.getRepaymentFrequency(), disbursedDate,
                // The application's chosen date, unless disbursement came too late for it.
                loan.getFirstRepaymentDate() != null && !loan.getFirstRepaymentDate().isBefore(disbursedDate)
                        ? loan.getFirstRepaymentDate() : null);

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

    /**
     * Closes a disbursed loan whose schedule isn't working and opens a new
     * linked loan carrying over the old loan's outstanding PRINCIPAL (the
     * receivable account's cached balance — interest/penalty are never
     * rolled over, same discipline the backend's RestructureLoanAction
     * follows) onto a fresh schedule under the chosen product's terms. No
     * fresh cash changes hands — see {@link #topUp} for that. Hybrid mode
     * pushes the outcome through the outbox afterward using the new loan's
     * own locally-generated id as its client_reference, so the server mints
     * the SAME id rather than a different one (unlike approve/reject/
     * disburse, this op creates a brand-new master record, so it needs the
     * same client-reference-as-id treatment as loan.apply).
     */
    public Loan restructure(String loanId, String restructuredBy, String newProductId, String reason) throws SQLException {
        Loan loan = requireStatus(loanId, LoanStatus.DISBURSED, "restructured");

        LoanProduct newProduct = productService.findById(newProductId);
        if (newProduct == null || !newProduct.isActive()) {
            throw new IllegalArgumentException("This loan product is no longer offered.");
        }

        long principalOutstanding = accountBalance(loan.getReceivableAccountId());
        if (principalOutstanding <= 0) {
            throw new IllegalStateException("Nothing to restructure — outstanding principal is already zero.");
        }

        Instant restructuredAt = Instant.now();
        LocalDate restructuredDate = LocalDate.ofInstant(restructuredAt, ZoneId.systemDefault());
        long refinanceAmount = loan.getOutstandingBalance();

        List<ScheduledInstallment> schedule = scheduleGenerator.generate(
                principalOutstanding, newProduct.getInterestRateBps(), newProduct.getTermPeriodCount(),
                newProduct.getInterestMethod(), newProduct.getRepaymentFrequency(), restructuredDate);
        long totalInterest = schedule.stream().mapToLong(ScheduledInstallment::interestDue).sum();
        long totalRepayable = principalOutstanding + totalInterest;

        String newLoanId = UUID.randomUUID().toString();
        String newLoanNumber;
        try (Connection conn = DatabaseConnection.getConnection()) {
            newLoanNumber = nextLoanNumber(conn);
        }

        String receivableAccountId = chart.loanReceivable(newLoanId, newLoanNumber).getId();

        List<LedgerLine> lines = new ArrayList<>();
        lines.add(LedgerLine.debit(receivableAccountId, principalOutstanding));
        lines.add(LedgerLine.credit(loan.getReceivableAccountId(), principalOutstanding));

        ledger.post(EntryRequest.of(TransactionType.LOAN_RESTRUCTURE, lines)
                .paymentMethod(PaymentMethod.CASH)
                .recordedBy(restructuredBy)
                .recordedAt(restructuredAt)
                .description("Loan restructure " + loan.getLoanNumber() + " -> " + newLoanNumber));

        String now = restructuredAt.toString();
        try (Connection conn = DatabaseConnection.getConnection()) {
            insertRefinancedLoan(conn, newLoanId, loan.getCustomerId(), newProduct.getId(),
                    loan.getSavingsAccountId(), loan.getAgentId(), restructuredBy, receivableAccountId,
                    newLoanNumber, principalOutstanding, newProduct.getInterestMethod().value(),
                    newProduct.getInterestRateBps(), newProduct.getTermPeriodCount(),
                    newProduct.getRepaymentFrequency().value(), newProduct.getOriginationFeeAmount(),
                    newProduct.getPenaltyRateBps(), newProduct.getGracePeriodDays(), totalInterest, totalRepayable,
                    loan.getGuarantorName(), loan.getGuarantorPhone(), loan.getId(), principalOutstanding, now);

            insertRefinancedInstallments(conn, newLoanId, schedule);

            closeRefinancedLoan(conn, loan.getId(), "restructure", reason, refinanceAmount, now);
        }

        outbox.enqueueIfHybrid("loan.restructure", new JSONObject()
                .put("loan_id", loanId)
                .put("loan_product_id", newProductId)
                .put("reason", reason)
                .put("client_reference", newLoanId));

        return findById(newLoanId);
    }

    /**
     * Closes a disbursed loan in good standing and opens a new linked loan
     * whose principal is the old loan's outstanding PRINCIPAL plus a
     * manager-entered top-up amount of fresh cash, reusing the OLD loan's
     * own terms (a top-up is "more of the same deal," not a renegotiation —
     * unlike {@link #restructure}, there's no product picker). Hybrid mode
     * pushes the outcome through the outbox afterward, same client-reference-
     * as-id reasoning as {@link #restructure}.
     */
    public Loan topUp(String loanId, String toppedUpBy, long topUpAmount, String reason) throws SQLException {
        Loan loan = requireStatus(loanId, LoanStatus.DISBURSED, "topped up");
        if (topUpAmount <= 0) {
            throw new IllegalArgumentException("The top-up amount must be greater than zero.");
        }
        boolean hasOverdue = findInstallments(loanId).stream()
                .anyMatch(i -> i.getStatus() == InstallmentStatus.OVERDUE);
        if (hasOverdue) {
            throw new IllegalStateException("This loan has an overdue installment and is not eligible for a top-up.");
        }

        long principalOutstanding = accountBalance(loan.getReceivableAccountId());
        long newPrincipal = principalOutstanding + topUpAmount;

        Instant toppedUpAt = Instant.now();
        LocalDate toppedUpDate = LocalDate.ofInstant(toppedUpAt, ZoneId.systemDefault());
        long refinanceAmount = loan.getOutstandingBalance();

        List<ScheduledInstallment> schedule = scheduleGenerator.generate(
                newPrincipal, loan.getInterestRateBps(), loan.getTermPeriodCount(),
                loan.getInterestMethod(), loan.getRepaymentFrequency(), toppedUpDate);
        long totalInterest = schedule.stream().mapToLong(ScheduledInstallment::interestDue).sum();
        long totalRepayable = newPrincipal + totalInterest;
        long netCash = topUpAmount - loan.getOriginationFeeAmount();

        String newLoanId = UUID.randomUUID().toString();
        String newLoanNumber;
        try (Connection conn = DatabaseConnection.getConnection()) {
            newLoanNumber = nextLoanNumber(conn);
        }

        String receivableAccountId = chart.loanReceivable(newLoanId, newLoanNumber).getId();

        List<LedgerLine> lines = new ArrayList<>();
        lines.add(LedgerLine.debit(receivableAccountId, newPrincipal));
        if (principalOutstanding > 0) {
            lines.add(LedgerLine.credit(loan.getReceivableAccountId(), principalOutstanding));
        }
        lines.add(LedgerLine.credit(chart.branchCash().getId(), netCash));
        if (loan.getOriginationFeeAmount() > 0) {
            lines.add(LedgerLine.credit(chart.loanFeeIncome().getId(), loan.getOriginationFeeAmount()));
        }

        ledger.post(EntryRequest.of(TransactionType.LOAN_TOP_UP, lines)
                .paymentMethod(PaymentMethod.CASH)
                .recordedBy(toppedUpBy)
                .recordedAt(toppedUpAt)
                .description("Loan top-up " + loan.getLoanNumber() + " -> " + newLoanNumber));

        String now = toppedUpAt.toString();
        try (Connection conn = DatabaseConnection.getConnection()) {
            insertRefinancedLoan(conn, newLoanId, loan.getCustomerId(), loan.getLoanProductId(),
                    loan.getSavingsAccountId(), loan.getAgentId(), toppedUpBy, receivableAccountId,
                    newLoanNumber, newPrincipal, loan.getInterestMethod().value(), loan.getInterestRateBps(),
                    loan.getTermPeriodCount(), loan.getRepaymentFrequency().value(), loan.getOriginationFeeAmount(),
                    loan.getPenaltyRateBps(), loan.getGracePeriodDays(), totalInterest, totalRepayable,
                    loan.getGuarantorName(), loan.getGuarantorPhone(), loan.getId(), principalOutstanding, now);

            insertRefinancedInstallments(conn, newLoanId, schedule);

            closeRefinancedLoan(conn, loan.getId(), "top_up", reason, refinanceAmount, now);
        }

        outbox.enqueueIfHybrid("loan.top_up", new JSONObject()
                .put("loan_id", loanId)
                .put("amount", topUpAmount)
                .put("reason", reason)
                .put("client_reference", newLoanId));

        return findById(newLoanId);
    }

    /**
     * Declares a disbursed loan's remaining balance uncollectible — a
     * single atomic transition, mirroring the backend's WriteOffLoanAction.
     * The ledger entry only moves the loan's remaining PRINCIPAL (Dr bad
     * debt expense / Cr loan receivable) since the receivable account only
     * ever holds principal — interest/penalty are recognized as income
     * solely when actually collected, per {@link #recordRepayment}.
     * write_off_amount still snapshots the full outstanding_balance for
     * reporting even though only the principal ever touched the ledger.
     * Hybrid mode pushes the outcome through the outbox afterward — no
     * client_reference needed here, unlike restructure/topUp, since this op
     * only closes an existing loan rather than minting a new one.
     */
    public Loan writeOff(String loanId, String writtenOffBy, String reason) throws SQLException {
        return writeOff(loanId, writtenOffBy, reason, null, 0);
    }

    /**
     * As {@link #writeOff(String, String, String)}, but first applies an
     * amount of the borrower's own savings against the receivable (Dr savings
     * liability / Cr receivable), so only the residual principal is booked as
     * bad debt. Capped at the principal still on the receivable — savings
     * can't settle interest that was never recognized as income.
     */
    public Loan writeOff(String loanId, String writtenOffBy, String reason, String savingsAccountId,
                         long savingsAmountApplied) throws SQLException {
        Loan loan = requireStatus(loanId, LoanStatus.DISBURSED, "written off");
        if (loan.getOutstandingBalance() <= 0) {
            throw new IllegalStateException("This loan has no outstanding balance to write off.");
        }

        long writeOffAmount = loan.getOutstandingBalance();
        long applied = 0;

        if (savingsAccountId != null && savingsAmountApplied > 0) {
            SavingsAccount account = accountService.findById(savingsAccountId);
            if (account == null || !account.getCustomerId().equals(loan.getCustomerId())) {
                throw new IllegalArgumentException("This savings account does not belong to the borrower.");
            }
            if (savingsAmountApplied > accountBalance(loan.getReceivableAccountId())) {
                throw new IllegalArgumentException("Cannot apply more than the outstanding principal.");
            }
            // Must run before the principal read below: this posting already credits
            // the receivable, so the re-read yields the reduced residual.
            accountService.applyToLoanReceivable(savingsAccountId, loan.getReceivableAccountId(),
                    savingsAmountApplied, writtenOffBy, TransactionType.SAVINGS_APPLIED_TO_LOAN_WRITE_OFF,
                    "Savings applied to write-off " + loan.getLoanNumber());
            applied = savingsAmountApplied;
        }

        long principalOutstanding = accountBalance(loan.getReceivableAccountId());

        if (principalOutstanding > 0) {
            List<LedgerLine> lines = new ArrayList<>();
            lines.add(LedgerLine.debit(chart.badDebtExpense().getId(), principalOutstanding));
            lines.add(LedgerLine.credit(loan.getReceivableAccountId(), principalOutstanding));

            ledger.post(EntryRequest.of(TransactionType.LOAN_WRITE_OFF, lines)
                    .paymentMethod(PaymentMethod.CASH)
                    .recordedBy(writtenOffBy)
                    .recordedAt(Instant.now())
                    .description("Loan write-off " + loan.getLoanNumber()));
        }

        String appliedAccountId = applied > 0 ? savingsAccountId : null;

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE loans SET status = ?, outstanding_balance = 0, written_off_at = ?, write_off_reason = ?,"
                     + " write_off_amount = ?, write_off_savings_account_id = ?, write_off_savings_applied = ?,"
                     + " approved_by = ?, updated_at = ? WHERE id = ?")) {
            String now = Instant.now().toString();
            ps.setString(1, LoanStatus.WRITTEN_OFF.value());
            ps.setString(2, now);
            ps.setString(3, reason);
            ps.setLong(4, writeOffAmount);
            ps.setString(5, appliedAccountId);
            ps.setLong(6, applied);
            ps.setString(7, writtenOffBy);
            ps.setString(8, now);
            ps.setString(9, loanId);
            ps.executeUpdate();
        }

        JSONObject payload = new JSONObject().put("loan_id", loanId).put("reason", reason);
        if (appliedAccountId != null) {
            payload.put("savings_account_id", appliedAccountId).put("savings_amount_applied", applied);
        }
        outbox.enqueueIfHybrid("loan.write_off", payload);

        return findById(loanId);
    }

    private void insertRefinancedLoan(Connection conn, String newLoanId, String customerId, String loanProductId,
                                       String savingsAccountId, String agentId, String approvedBy,
                                       String receivableAccountId, String loanNumber, long principalAmount,
                                       String interestMethod, int interestRateBps, int termPeriodCount,
                                       String repaymentFrequency, long originationFeeAmount, int penaltyRateBps,
                                       int gracePeriodDays, long totalInterest, long totalRepayable,
                                       String guarantorName, String guarantorPhone, String previousLoanId,
                                       long rolledOverAmount, String now) throws SQLException {
        String sql = "INSERT INTO loans (id, customer_id, loan_product_id, savings_account_id, agent_id,"
                + " approved_by, receivable_account_id, loan_number, principal_amount, interest_method,"
                + " interest_rate_bps, term_period_count, repayment_frequency, origination_fee_amount,"
                + " penalty_rate_bps, grace_period_days, total_interest, total_repayable, outstanding_balance,"
                + " status, guarantor_name, guarantor_phone, previous_loan_id, rolled_over_amount, applied_at,"
                + " approved_at, disbursed_at, created_at, updated_at)"
                + " VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        try (PreparedStatement ps = conn.prepareStatement(sql)) {
            ps.setString(1, newLoanId);
            ps.setString(2, customerId);
            ps.setString(3, loanProductId);
            ps.setString(4, savingsAccountId);
            ps.setString(5, agentId);
            ps.setString(6, approvedBy);
            ps.setString(7, receivableAccountId);
            ps.setString(8, loanNumber);
            ps.setLong(9, principalAmount);
            ps.setString(10, interestMethod);
            ps.setInt(11, interestRateBps);
            ps.setInt(12, termPeriodCount);
            ps.setString(13, repaymentFrequency);
            ps.setLong(14, originationFeeAmount);
            ps.setInt(15, penaltyRateBps);
            ps.setInt(16, gracePeriodDays);
            ps.setLong(17, totalInterest);
            ps.setLong(18, totalRepayable);
            ps.setLong(19, totalRepayable);
            ps.setString(20, LoanStatus.DISBURSED.value());
            ps.setString(21, guarantorName);
            ps.setString(22, guarantorPhone);
            ps.setString(23, previousLoanId);
            ps.setLong(24, rolledOverAmount);
            ps.setString(25, now);
            ps.setString(26, now);
            ps.setString(27, now);
            ps.setString(28, now);
            ps.setString(29, now);
            ps.executeUpdate();
        }
    }

    private void insertRefinancedInstallments(Connection conn, String newLoanId, List<ScheduledInstallment> schedule)
            throws SQLException {
        for (ScheduledInstallment installment : schedule) {
            String sql = "INSERT INTO loan_installments (id, loan_id, sequence, due_date, principal_due,"
                    + " interest_due, penalty_due, principal_paid, interest_paid, penalty_paid, status,"
                    + " created_at, updated_at) VALUES (?,?,?,?,?,?,0,0,0,0,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                String instNow = Instant.now().toString();
                ps.setString(1, UUID.randomUUID().toString());
                ps.setString(2, newLoanId);
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

    private void closeRefinancedLoan(Connection conn, String oldLoanId, String refinanceType, String reason,
                                      long refinanceAmount, String now) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement(
                "UPDATE loans SET status = ?, outstanding_balance = 0, refinanced_at = ?, refinance_type = ?,"
                + " refinance_reason = ?, refinance_amount = ?, updated_at = ? WHERE id = ?")) {
            ps.setString(1, LoanStatus.REFINANCED.value());
            ps.setString(2, now);
            ps.setString(3, refinanceType);
            ps.setString(4, reason);
            ps.setLong(5, refinanceAmount);
            ps.setString(6, now);
            ps.setString(7, oldLoanId);
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

    /**
     * Re-dates a disbursed loan's unpaid installments — parity port of the
     * backend's RecalculateRepaymentScheduleAction. Amounts, payments and the
     * ledger are untouched; paid installments keep their dates. Unpaid ones
     * step one period apart from {@code firstDueDate}, or — when null — from
     * the loan's own rule (first repayment date, else one period after
     * disbursement). An overdue installment moved into the future is
     * un-flagged; newly late ones are left to {@link #flagArrears()}.
     *
     * @return the number of installments rescheduled
     */
    public int recalculateSchedule(String loanId, String recalculatedBy, LocalDate firstDueDate, String reason)
            throws SQLException {
        Loan loan = requireStatus(loanId, LoanStatus.DISBURSED, "rescheduled");
        List<LoanInstallment> unpaid = findInstallments(loanId).stream()
                .filter(installment -> installment.getStatus() != InstallmentStatus.PAID)
                .sorted(Comparator.comparingInt(LoanInstallment::getSequence))
                .toList();
        if (unpaid.isEmpty()) {
            throw new IllegalStateException("Every installment is already paid — there is nothing to reschedule.");
        }

        LocalDate today = LocalDate.now();
        String now = Instant.now().toString();
        LocalDate dueDate = null;

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE loan_installments SET due_date = ?, status = ?, updated_at = ? WHERE id = ?")) {
            for (LoanInstallment installment : unpaid) {
                if (dueDate != null) {
                    dueDate = loan.getRepaymentFrequency().addPeriod(dueDate, 1);
                } else if (firstDueDate != null) {
                    dueDate = firstDueDate;
                } else {
                    dueDate = ruleDueDate(loan, installment.getSequence());
                }

                long paid = installment.getPrincipalPaid() + installment.getInterestPaid() + installment.getPenaltyPaid();
                InstallmentStatus status = installment.getStatus() == InstallmentStatus.OVERDUE && dueDate.isBefore(today)
                        ? InstallmentStatus.OVERDUE
                        : (paid > 0 ? InstallmentStatus.PARTIALLY_PAID : InstallmentStatus.PENDING);

                ps.setString(1, dueDate.format(DateTimeFormatter.ISO_LOCAL_DATE));
                ps.setString(2, status.value());
                ps.setString(3, now);
                ps.setString(4, installment.getId());
                ps.executeUpdate();
            }
        }

        outbox.enqueueIfHybrid("loan.schedule.recalculate", new JSONObject()
                .put("loan_id", loanId)
                .putOpt("first_due_date", firstDueDate != null ? firstDueDate.toString() : null)
                .putOpt("reason", reason));

        return unpaid.size();
    }

    /** Where installment N falls under the loan's original dating rule (stepped like ScheduleGenerator). */
    private LocalDate ruleDueDate(Loan loan, int sequence) {
        LocalDate disbursedDate = LocalDate.ofInstant(loan.getDisbursedAt(), ZoneId.systemDefault());
        LocalDate anchor;
        int steps;
        if (loan.getFirstRepaymentDate() != null && !loan.getFirstRepaymentDate().isBefore(disbursedDate)) {
            anchor = loan.getFirstRepaymentDate();
            steps = sequence - 1;
        } else {
            anchor = disbursedDate;
            steps = sequence;
        }
        for (int step = 0; step < steps; step++) {
            anchor = loan.getRepaymentFrequency().addPeriod(anchor, 1);
        }
        return anchor;
    }

    public List<LoanApplicationDetails.Charge> findCharges(String loanId) throws SQLException {
        List<LoanApplicationDetails.Charge> charges = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT name, amount FROM loan_charges WHERE loan_id = ? ORDER BY created_at")) {
            ps.setString(1, loanId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    charges.add(new LoanApplicationDetails.Charge(rs.getString("name"), rs.getLong("amount")));
                }
            }
        }
        return charges;
    }

    public List<LoanApplicationDetails.Collateral> findCollaterals(String loanId) throws SQLException {
        List<LoanApplicationDetails.Collateral> collaterals = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT type, description, estimated_value, serial_number, notes FROM loan_collaterals WHERE loan_id = ? ORDER BY created_at")) {
            ps.setString(1, loanId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    collaterals.add(new LoanApplicationDetails.Collateral(rs.getString("type"), rs.getString("description"),
                            rs.getLong("estimated_value"), rs.getString("serial_number"), rs.getString("notes")));
                }
            }
        }
        return collaterals;
    }

    public List<LoanApplicationDetails.Guarantor> findGuarantors(String loanId) throws SQLException {
        List<LoanApplicationDetails.Guarantor> guarantors = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT customer_id, name, phone, relationship, address, guaranteed_amount FROM loan_guarantors WHERE loan_id = ? ORDER BY created_at")) {
            ps.setString(1, loanId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    long amount = rs.getLong("guaranteed_amount");
                    Long guaranteedAmount = rs.wasNull() ? null : amount; // read before any other column
                    guarantors.add(new LoanApplicationDetails.Guarantor(rs.getString("customer_id"), rs.getString("name"),
                            rs.getString("phone"), rs.getString("relationship"), rs.getString("address"),
                            guaranteedAmount));
                }
            }
        }
        return guarantors;
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

    /**
     * Flags installments overdue past their grace period and accrues a
     * one-time late penalty — the standalone-mode mirror of the backend's
     * scheduled {@code loans:flag-arrears} command. This desktop app has no
     * background scheduler, so {@code MainController} runs this once per app
     * launch instead of daily; the {@code penalty_due = 0} guard still makes
     * repeat runs (multiple opens per day) a no-op for installments already
     * flagged, keeping loan.outstanding_balance in sync the same way.
     */
    public int flagArrears() throws SQLException {
        record Candidate(String installmentId, String loanId, LocalDate dueDate, long remainingPrincipal,
                          long remainingInterest, int gracePeriodDays, int penaltyRateBps) {}

        List<Candidate> candidates = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT li.id AS installment_id, li.loan_id, li.due_date,"
                     + " (li.principal_due - li.principal_paid) AS remaining_principal,"
                     + " (li.interest_due - li.interest_paid) AS remaining_interest,"
                     + " l.grace_period_days, l.penalty_rate_bps"
                     + " FROM loan_installments li JOIN loans l ON l.id = li.loan_id"
                     + " WHERE li.status IN ('pending', 'partially_paid') AND li.penalty_due = 0"
                     + " AND l.status = 'disbursed'");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                candidates.add(new Candidate(
                        rs.getString("installment_id"),
                        rs.getString("loan_id"),
                        LocalDate.parse(rs.getString("due_date")),
                        Math.max(0, rs.getLong("remaining_principal")),
                        Math.max(0, rs.getLong("remaining_interest")),
                        rs.getInt("grace_period_days"),
                        rs.getInt("penalty_rate_bps")));
            }
        }

        LocalDate today = LocalDate.now();
        int flagged = 0;
        for (Candidate c : candidates) {
            LocalDate graceDeadline = c.dueDate().plusDays(c.gracePeriodDays());
            if (!today.isAfter(graceDeadline)) {
                continue;
            }

            long overdueAmount = c.remainingPrincipal() + c.remainingInterest();
            long penalty = (overdueAmount * c.penaltyRateBps()) / 10_000;
            String now = Instant.now().toString();

            try (Connection conn = DatabaseConnection.getConnection();
                 PreparedStatement ps = conn.prepareStatement(
                         "UPDATE loan_installments SET status = 'overdue', penalty_due = ?, updated_at = ?"
                         + " WHERE id = ?")) {
                ps.setLong(1, penalty);
                ps.setString(2, now);
                ps.setString(3, c.installmentId());
                ps.executeUpdate();
            }

            if (penalty > 0) {
                try (Connection conn = DatabaseConnection.getConnection();
                     PreparedStatement ps = conn.prepareStatement(
                             "UPDATE loans SET outstanding_balance = outstanding_balance + ?, updated_at = ?"
                             + " WHERE id = ?")) {
                    ps.setLong(1, penalty);
                    ps.setString(2, now);
                    ps.setString(3, c.loanId());
                    ps.executeUpdate();
                }
            }

            flagged++;
        }

        return flagged;
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
        loan.setPurpose(rs.getString("purpose"));
        String firstRepaymentDate = rs.getString("first_repayment_date");
        loan.setFirstRepaymentDate(firstRepaymentDate != null ? LocalDate.parse(firstRepaymentDate) : null);
        loan.setClientReference(rs.getString("client_reference"));
        loan.setAppliedAt(Instant.parse(rs.getString("applied_at")));
        String approvedAt = rs.getString("approved_at");
        loan.setApprovedAt(approvedAt != null ? Instant.parse(approvedAt) : null);
        String disbursedAt = rs.getString("disbursed_at");
        loan.setDisbursedAt(disbursedAt != null ? Instant.parse(disbursedAt) : null);
        String closedAt = rs.getString("closed_at");
        loan.setClosedAt(closedAt != null ? Instant.parse(closedAt) : null);
        loan.setPreviousLoanId(rs.getString("previous_loan_id"));
        loan.setRolledOverAmount(rs.getLong("rolled_over_amount"));
        String refinancedAt = rs.getString("refinanced_at");
        loan.setRefinancedAt(refinancedAt != null ? Instant.parse(refinancedAt) : null);
        loan.setRefinanceType(rs.getString("refinance_type"));
        loan.setRefinanceReason(rs.getString("refinance_reason"));
        long refinanceAmount = rs.getLong("refinance_amount");
        loan.setRefinanceAmount(rs.wasNull() ? null : refinanceAmount);
        String writtenOffAt = rs.getString("written_off_at");
        loan.setWrittenOffAt(writtenOffAt != null ? Instant.parse(writtenOffAt) : null);
        loan.setWriteOffReason(rs.getString("write_off_reason"));
        long writeOffAmount = rs.getLong("write_off_amount");
        loan.setWriteOffAmount(rs.wasNull() ? null : writeOffAmount);
        loan.setWriteOffSavingsAccountId(rs.getString("write_off_savings_account_id"));
        long writeOffSavingsApplied = rs.getLong("write_off_savings_applied");
        loan.setWriteOffSavingsApplied(rs.wasNull() ? null : writeOffSavingsApplied);
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
