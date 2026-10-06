package service;

import db.DatabaseConnection;
import enums.AccountStatus;
import enums.PaymentMethod;
import enums.TransactionType;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.util.List;
import java.util.UUID;
import models.JournalEntry;
import models.SavingsAccount;
import models.SavingsProduct;
import org.json.JSONObject;

/**
 * Records a susu deposit: Dr agent cash / Cr customer savings liability, plus
 * the commission entry when a new cycle starts. Idempotent on client
 * reference so a replayed op can never double-post. A disciplined,
 * version-stamped ({@link LedgerService#ENGINE_VERSION}) parity port of the
 * backend's {@code App\Actions\Savings\RecordCollectionAction} — this is the
 * standalone-mode operations engine's core action (AD-5).
 */
public class CollectionService {

    private final LedgerService ledger = new LedgerService();
    private final ChartOfAccounts chart = new ChartOfAccounts();
    private final CommissionCalculator commissionCalculator = new CommissionCalculator();
    private final SavingsAccountService accountService = new SavingsAccountService();
    private final SavingsProductService productService = new SavingsProductService();
    private final AgentDailySummaryService summaryService = new AgentDailySummaryService();
    private final OutboxService outbox = new OutboxService();

    public CollectionResult record(String agentId, String agentName, String accountId, long amount,
                                    String clientReference, Instant recordedAt) throws SQLException {
        String effectiveClientReference = clientReference != null ? clientReference : UUID.randomUUID().toString();

        JournalEntry existing = ledger.findByClientReference(effectiveClientReference);
        if (existing != null) {
            SavingsAccount account = accountService.findById(accountId);
            return new CollectionResult(existing, account, 0, true);
        }

        SavingsAccount account = accountService.findById(accountId);
        if (account == null) {
            throw new IllegalArgumentException("Account not found.");
        }
        if (account.getStatus() == AccountStatus.CLOSED) {
            throw new IllegalArgumentException("This savings account is closed.");
        }

        SavingsProduct product = productService.findById(account.getSavingsProductId());
        if (product != null && product.isShares()) {
            throw new IllegalArgumentException("Share accounts are funded by buying shares, not by collections.");
        }
        if (amount <= 0 || account.getContributionAmount() <= 0 || amount % account.getContributionAmount() != 0) {
            throw new IllegalArgumentException(
                    "Amount must be a positive multiple of the daily contribution ("
                    + account.getContributionAmount() + ").");
        }
        // Mirrors RecordCollectionAction: a fixed deposit is funded once, with
        // exactly its principal, before it matures.
        if (product != null && product.isFixedDeposit()) {
            if (account.getMaturedAt() != null || account.getBalance() > 0) {
                throw new IllegalArgumentException("This fixed deposit is already funded and cannot take further deposits.");
            }
            if (amount != account.getContributionAmount()) {
                throw new IllegalArgumentException(
                        "A fixed deposit must be funded with exactly its principal ("
                        + account.getContributionAmount() + ").");
            }
        }
        Instant effectiveRecordedAt = recordedAt != null ? recordedAt : Instant.now();
        int units = (int) (amount / account.getContributionAmount());
        CycleResult cycle = commissionCalculator.simulate(account, product, units, amount);
        long balanceAfter = account.getBalance() + amount - cycle.commissionAmount();

        JournalEntry entry = ledger.post(EntryRequest.of(TransactionType.COLLECTION, List.of(
                LedgerLine.debit(chart.agentCash(agentId, agentName).getId(), amount),
                LedgerLine.credit(account.getLedgerAccountId(), amount)
        )).paymentMethod(PaymentMethod.CASH)
                .recordedBy(agentId)
                .recordedAt(effectiveRecordedAt)
                .clientReference(effectiveClientReference)
                .description("Susu collection " + account.getAccountNumber()));

        if (cycle.commissionAmount() > 0) {
            ledger.post(EntryRequest.of(TransactionType.COMMISSION, List.of(
                    LedgerLine.debit(account.getLedgerAccountId(), cycle.commissionAmount()),
                    LedgerLine.credit(chart.commissionIncome().getId(), cycle.commissionAmount())
            )).paymentMethod(PaymentMethod.INTERNAL)
                    .recordedBy(agentId)
                    .recordedAt(effectiveRecordedAt)
                    .description("Cycle commission " + account.getAccountNumber()));
        }

        updateAccountAfterCollection(account.getId(), cycle, balanceAfter, effectiveRecordedAt, cycle.cyclesStarted() > 0);

        summaryService.trackCollection(agentId, amount, effectiveRecordedAt);

        outbox.enqueueIfHybrid("collection.record", new JSONObject()
                .put("savings_account_id", accountId)
                .put("amount", amount)
                .put("client_reference", effectiveClientReference)
                .put("recorded_at", effectiveRecordedAt.toString()));

        SavingsAccount refreshedAccount = accountService.findById(accountId);
        return new CollectionResult(entry, refreshedAccount, cycle.commissionAmount(), false);
    }

    private void updateAccountAfterCollection(String accountId, CycleResult cycle, long balanceAfter,
                                               Instant recordedAt, boolean cycleStarted) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection()) {
            String sql = "UPDATE savings_accounts SET contributions_this_cycle = ?, cycle_number = ?,"
                    + " cycle_started_at = CASE WHEN ? THEN ? ELSE cycle_started_at END,"
                    + " balance = ?, status = ?, updated_at = ? WHERE id = ?";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setInt(1, cycle.newContributionsThisCycle());
                ps.setInt(2, cycle.newCycleNumber());
                ps.setInt(3, cycleStarted ? 1 : 0);
                ps.setString(4, LocalDate.ofInstant(recordedAt, java.time.ZoneId.systemDefault())
                        .format(DateTimeFormatter.ISO_LOCAL_DATE));
                ps.setLong(5, balanceAfter);
                ps.setString(6, AccountStatus.ACTIVE.value());
                ps.setString(7, Instant.now().toString());
                ps.setString(8, accountId);
                ps.executeUpdate();
            }
        }
    }
}
