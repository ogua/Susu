package service;

import db.DatabaseConnection;
import enums.AccountStatus;
import enums.PaymentMethod;
import enums.TransactionType;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.SQLException;
import java.time.Instant;
import java.util.List;
import java.util.UUID;
import models.JournalEntry;
import models.SavingsAccount;
import models.SavingsProduct;

/**
 * Buys shares into a cooperative share-capital account: Dr agent cash / Cr
 * the account's savings liability, for shares * product.par_value. A
 * disciplined, version-stamped ({@link LedgerService#ENGINE_VERSION}) parity
 * port of the backend's {@code App\Actions\Savings\BuySharesAction}.
 * Deliberately a separate class from {@link SavingsAccountService} (which
 * posts no ledger entries at all) — structurally closest to
 * {@link CollectionService}, which also posts a cash-in ledger entry.
 * Idempotent on client reference, mirrors CollectionService's dedup shape.
 *
 * <p>Deliberately does NOT enqueue anything to the outbox: there is no
 * {@code SyncOpType::BuyShares} on the backend (see
 * {@code VerifyPaymentIntentAction}'s Shares branch, which only serves the
 * mobile customer-facing flow), and enqueueing an op_type the server can't
 * recognize would abort the *entire* sync batch it's replayed in — not just
 * this op. A hybrid-mode desktop's share purchases stay local-only for now,
 * same as the rest of this class's read-mostly footprint until that op is
 * built following the loan.restructure/loan.top_up wiring pattern.</p>
 */
public class SharesService {

    public record SharesResult(JournalEntry entry, SavingsAccount account, long amount, boolean duplicate) {}

    private final LedgerService ledger = new LedgerService();
    private final ChartOfAccounts chart = new ChartOfAccounts();
    private final SavingsAccountService accountService = new SavingsAccountService();
    private final SavingsProductService productService = new SavingsProductService();

    public SharesResult buyShares(String agentId, String agentName, String accountId, int shares,
                                   String clientReference, Instant recordedAt) throws SQLException {
        String effectiveClientReference = clientReference != null ? clientReference : UUID.randomUUID().toString();

        JournalEntry existing = ledger.findByClientReference(effectiveClientReference);
        if (existing != null) {
            SavingsAccount account = accountService.findById(accountId);
            return new SharesResult(existing, account, 0, true);
        }

        SavingsAccount account = accountService.findById(accountId);
        if (account == null) {
            throw new IllegalArgumentException("Account not found.");
        }
        if (account.getStatus() == AccountStatus.CLOSED) {
            throw new IllegalArgumentException("This savings account is closed.");
        }

        SavingsProduct product = productService.findById(account.getSavingsProductId());
        if (product == null || !product.isShares()) {
            throw new IllegalArgumentException("This account does not support share purchases.");
        }
        if (shares <= 0) {
            throw new IllegalArgumentException("Must purchase at least one share.");
        }
        if (product.getParValue() == null) {
            throw new IllegalStateException("This shares product has no par value configured.");
        }

        long amount = (long) shares * product.getParValue();
        Instant effectiveRecordedAt = recordedAt != null ? recordedAt : Instant.now();

        JournalEntry entry = ledger.post(EntryRequest.of(TransactionType.SHARE_PURCHASE, List.of(
                LedgerLine.debit(chart.agentCash(agentId, agentName).getId(), amount),
                LedgerLine.credit(account.getLedgerAccountId(), amount)
        )).paymentMethod(PaymentMethod.CASH)
                .recordedBy(agentId)
                .recordedAt(effectiveRecordedAt)
                .clientReference(effectiveClientReference)
                .description("Share purchase " + account.getAccountNumber() + " (" + shares + " shares)"));

        // Opened after the ledger post's connection has closed — the SQLite
        // pool is single-connection, so nesting acquisitions here would
        // self-deadlock.
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE savings_accounts SET share_count = share_count + ?, balance = balance + ?,"
                     + " status = ?, updated_at = ? WHERE id = ?")) {
            ps.setInt(1, shares);
            ps.setLong(2, amount);
            ps.setString(3, AccountStatus.ACTIVE.value());
            ps.setString(4, Instant.now().toString());
            ps.setString(5, accountId);
            ps.executeUpdate();
        }

        SavingsAccount refreshedAccount = accountService.findById(accountId);
        return new SharesResult(entry, refreshedAccount, amount, false);
    }
}
