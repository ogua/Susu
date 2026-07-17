package service;

import db.DatabaseConnection;
import enums.InterestMethod;
import enums.LoanFrequency;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import models.LoanProduct;

/**
 * Loan product catalogue. Standalone offices are typically single-product,
 * so a default is lazily provisioned on first use rather than requiring a
 * setup step — mirrors {@link SavingsProductService#getOrCreateDefault()}.
 */
public class LoanProductService {

    public LoanProduct getOrCreateDefault() throws SQLException {
        List<LoanProduct> active = findActive();
        if (!active.isEmpty()) {
            return active.get(0);
        }

        try (Connection conn = DatabaseConnection.getConnection()) {
            String id = UUID.randomUUID().toString();
            String now = Instant.now().toString();
            String sql = "INSERT INTO loan_products (id, name, code, interest_method, interest_rate_bps,"
                    + " term_period_count, repayment_frequency, origination_fee_amount, penalty_rate_bps,"
                    + " grace_period_days, min_amount, max_amount, is_active, created_at, updated_at)"
                    + " VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, "Standard Loan");
                ps.setString(3, "LN-001");
                ps.setString(4, InterestMethod.FLAT.value());
                ps.setInt(5, 300); // 3% per period
                ps.setInt(6, 3);
                ps.setString(7, LoanFrequency.MONTHLY.value());
                ps.setLong(8, 0);
                ps.setInt(9, 0);
                ps.setInt(10, 3);
                ps.setLong(11, 100_00);
                ps.setLong(12, 2_000_00);
                ps.setString(13, now);
                ps.setString(14, now);
                ps.executeUpdate();
            }
        }

        return findActive().get(0);
    }

    /** Every product, active or not — the management screen's listing. */
    public List<LoanProduct> findAll() throws SQLException {
        List<LoanProduct> products = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM loan_products ORDER BY name");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                products.add(map(rs));
            }
        }
        return products;
    }

    /** Creates a product from the management screen. Returns the stored row. */
    public LoanProduct create(LoanProduct product) throws SQLException {
        String id = UUID.randomUUID().toString();
        String now = Instant.now().toString();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "INSERT INTO loan_products (id, name, code, interest_method, interest_rate_bps,"
                     + " term_period_count, repayment_frequency, origination_fee_amount, penalty_rate_bps,"
                     + " grace_period_days, min_amount, max_amount, is_active, created_at, updated_at)"
                     + " VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)")) {
            ps.setString(1, id);
            ps.setString(2, product.getName());
            ps.setString(3, product.getCode());
            ps.setString(4, product.getInterestMethod().value());
            ps.setInt(5, product.getInterestRateBps());
            ps.setInt(6, product.getTermPeriodCount());
            ps.setString(7, product.getRepaymentFrequency().value());
            ps.setLong(8, product.getOriginationFeeAmount());
            ps.setInt(9, product.getPenaltyRateBps());
            ps.setInt(10, product.getGracePeriodDays());
            ps.setLong(11, product.getMinAmount());
            ps.setLong(12, product.getMaxAmount());
            ps.setString(13, now);
            ps.setString(14, now);
            ps.executeUpdate();
        }
        return findById(id);
    }

    public void setActive(String id, boolean active) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE loan_products SET is_active = ?, updated_at = ? WHERE id = ?")) {
            ps.setInt(1, active ? 1 : 0);
            ps.setString(2, Instant.now().toString());
            ps.setString(3, id);
            ps.executeUpdate();
        }
    }

    public List<LoanProduct> findActive() throws SQLException {
        List<LoanProduct> products = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM loan_products WHERE is_active = 1 ORDER BY name");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                products.add(map(rs));
            }
        }
        return products;
    }

    public LoanProduct findById(String id) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM loan_products WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? map(rs) : null;
            }
        }
    }

    private LoanProduct map(ResultSet rs) throws SQLException {
        LoanProduct product = new LoanProduct();
        product.setId(rs.getString("id"));
        product.setName(rs.getString("name"));
        product.setCode(rs.getString("code"));
        product.setInterestMethod(InterestMethod.fromValue(rs.getString("interest_method")));
        product.setInterestRateBps(rs.getInt("interest_rate_bps"));
        product.setTermPeriodCount(rs.getInt("term_period_count"));
        product.setRepaymentFrequency(LoanFrequency.fromValue(rs.getString("repayment_frequency")));
        product.setOriginationFeeAmount(rs.getLong("origination_fee_amount"));
        product.setPenaltyRateBps(rs.getInt("penalty_rate_bps"));
        product.setGracePeriodDays(rs.getInt("grace_period_days"));
        product.setMinAmount(rs.getLong("min_amount"));
        product.setMaxAmount(rs.getLong("max_amount"));
        product.setActive(rs.getInt("is_active") != 0);
        return product;
    }
}
