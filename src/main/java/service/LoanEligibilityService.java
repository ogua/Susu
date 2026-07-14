package service;

import db.DatabaseConnection;
import enums.AccountStatus;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Duration;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import models.SavingsAccount;

/**
 * Susu-history-based loan eligibility (account age, consistency, average
 * balance). Mirrors the backend's {@code App\Services\Loans\EligibilityService}
 * — thresholds must stay in lockstep with it.
 */
public class LoanEligibilityService {

    private static final long MIN_ACCOUNT_AGE_DAYS = 60;

    private static final long MIN_COLLECTIONS_COUNT = 20;

    /** A customer may borrow at most this many times their current savings balance. */
    private static final long MAX_LOAN_TO_BALANCE_MULTIPLE = 3;

    public LoanEligibilityResult evaluate(SavingsAccount account, long requestedAmount) throws SQLException {
        List<String> reasons = new ArrayList<>();

        if (account.getStatus() == AccountStatus.CLOSED) {
            reasons.add("The savings account is closed.");
        }

        long ageDays = Duration.between(Instant.parse(account.getOpenedAt()), Instant.now()).toDays();
        if (ageDays < MIN_ACCOUNT_AGE_DAYS) {
            reasons.add("Account must be at least " + MIN_ACCOUNT_AGE_DAYS
                    + " days old (currently " + ageDays + ").");
        }

        long collectionsCount = countCollections(account.getLedgerAccountId());
        if (collectionsCount < MIN_COLLECTIONS_COUNT) {
            reasons.add("At least " + MIN_COLLECTIONS_COUNT
                    + " prior collections are required (currently " + collectionsCount + ").");
        }

        long maxLoanAmount = account.getBalance() * MAX_LOAN_TO_BALANCE_MULTIPLE;
        if (requestedAmount > maxLoanAmount) {
            reasons.add("Requested amount exceeds " + MAX_LOAN_TO_BALANCE_MULTIPLE
                    + "x the account's current balance.");
        }

        return new LoanEligibilityResult(reasons.isEmpty(), reasons);
    }

    private long countCollections(String ledgerAccountId) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT COUNT(*) FROM journal_entries je"
                     + " JOIN journal_lines jl ON jl.journal_entry_id = je.id"
                     + " WHERE jl.ledger_account_id = ? AND je.type = 'collection'")) {
            ps.setString(1, ledgerAccountId);
            try (ResultSet rs = ps.executeQuery()) {
                rs.next();
                return rs.getLong(1);
            }
        }
    }
}
