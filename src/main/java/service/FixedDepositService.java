package service;

import db.DatabaseConnection;
import enums.PaymentMethod;
import enums.TransactionType;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.time.LocalDate;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.List;

/**
 * Matures fixed-deposit accounts whose matures_at date has arrived: credits
 * principal-held interest (rate * term_days / 365, using the account's own
 * snapshotted interest_rate_bps, not the product's current one) into the
 * account's balance and flips matured_at, unlocking withdrawal. The
 * standalone-mode mirror of the backend's
 * {@code App\Actions\Savings\MatureFixedDepositAction}, run as a sweep the
 * same way {@link LoanService#flagArrears()} is — this desktop app has no
 * background scheduler, so {@code MainController} runs this once per app
 * launch instead of on the backend's schedule. The {@code matured_at IS NULL}
 * filter on the candidate query is itself the idempotency guard, same idiom
 * as {@code flagArrears()}'s {@code penalty_due = 0} guard.
 *
 * <p>This is the first maturity/sweep action ported to desktop at all — no
 * Target-maturity precedent exists to extend, and this class does not build
 * one.</p>
 */
public class FixedDepositService {

    private final LedgerService ledger = new LedgerService();
    private final ChartOfAccounts chart = new ChartOfAccounts();

    public int matureFixedDeposits() throws SQLException {
        record Candidate(String accountId, String accountNumber, String ledgerAccountId, long balance, int rateBps,
                          LocalDate accruesFrom, LocalDate maturesAt) {}

        List<Candidate> candidates = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT sa.id, sa.account_number, sa.ledger_account_id, sa.balance, sa.interest_rate_bps,"
                     + " sa.opened_at, sa.cycle_started_at, sa.matures_at FROM savings_accounts sa"
                     + " JOIN savings_products sp ON sp.id = sa.savings_product_id"
                     + " WHERE sp.type = 'fixed_deposit' AND sa.matures_at IS NOT NULL"
                     + " AND sa.matured_at IS NULL AND sa.matures_at <= ?")) {
            ps.setString(1, LocalDate.now().toString());
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    // Interest runs from the funding deposit (which stamps
                    // cycle_started_at), falling back to opening for older accounts.
                    String fundedOn = rs.getString("cycle_started_at");
                    candidates.add(new Candidate(
                            rs.getString("id"),
                            rs.getString("account_number"),
                            rs.getString("ledger_account_id"),
                            rs.getLong("balance"),
                            rs.getInt("interest_rate_bps"),
                            fundedOn != null
                                    ? LocalDate.parse(fundedOn.substring(0, 10))
                                    : LocalDate.ofInstant(Instant.parse(rs.getString("opened_at")),
                                            java.time.ZoneId.systemDefault()),
                            LocalDate.parse(rs.getString("matures_at"))));
                }
            }
        }

        int matured = 0;
        for (Candidate c : candidates) {
            long termDays = Math.max(0, ChronoUnit.DAYS.between(c.accruesFrom(), c.maturesAt()));
            long interest = (c.balance() * c.rateBps() * termDays) / (10_000L * 365);

            if (interest > 0) {
                ledger.post(EntryRequest.of(TransactionType.FIXED_DEPOSIT_MATURITY, List.of(
                        LedgerLine.debit(chart.savingsInterestExpense().getId(), interest),
                        LedgerLine.credit(c.ledgerAccountId(), interest)
                )).paymentMethod(PaymentMethod.INTERNAL)
                        .recordedAt(Instant.now())
                        .description("Fixed deposit maturity interest " + c.accountNumber()));
            }

            String now = Instant.now().toString();
            try (Connection conn = DatabaseConnection.getConnection();
                 PreparedStatement ps = conn.prepareStatement(
                         "UPDATE savings_accounts SET balance = balance + ?, matured_at = ?, updated_at = ?"
                         + " WHERE id = ?")) {
                ps.setLong(1, interest);
                ps.setString(2, now);
                ps.setString(3, now);
                ps.setString(4, c.accountId());
                ps.executeUpdate();
            }

            matured++;
        }

        return matured;
    }
}
