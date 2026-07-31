package service;

import db.DatabaseConnection;
import enums.CommissionType;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import models.SavingsProduct;
import org.json.JSONObject;

/**
 * Susu product catalogue. Standalone offices are typically single-product
 * ("Daily Susu"), so a default is lazily provisioned on first use rather
 * than requiring a setup step — {@link #getOrCreateDefault()} is what the
 * account-opening screen calls.
 *
 * <p>Hybrid mode: {@link #upsertFromServer} is called by
 * {@link SyncService#pullProducts()} to mirror the company's real product
 * catalogue locally (same id as the server's row), so a later
 * {@code account.open} sync op's {@code savings_product_id} always resolves.
 * As long as a pull has happened before the first account is opened,
 * {@link #getOrCreateDefault()} picks up the synced product instead of
 * inventing a local-only one, since it simply returns the first active
 * product found.</p>
 */
public class SavingsProductService {

    /** Insert-or-update by server id — called from the sync pull path only. */
    public void upsertFromServer(JSONObject product) throws SQLException {
        String id = product.getString("id");
        try (Connection conn = DatabaseConnection.getConnection()) {
            if (findById(conn, id) != null) {
                String sql = "UPDATE savings_products SET name = ?, code = ?, type = ?, contribution_amount = ?,"
                        + " cycle_length_days = ?, commission_type = ?, commission_value = ?, interest_rate_bps = ?,"
                        + " par_value = ?, is_active = ?, updated_at = ? WHERE id = ?";
                try (PreparedStatement ps = conn.prepareStatement(sql)) {
                    bindProduct(ps, product);
                    ps.setString(12, id);
                    ps.executeUpdate();
                }
                return;
            }

            String now = Instant.now().toString();
            String sql = "INSERT INTO savings_products (id, name, code, type, contribution_amount,"
                    + " cycle_length_days, commission_type, commission_value, interest_rate_bps, par_value,"
                    + " is_active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, product.getString("name"));
                ps.setString(3, product.optString("code", null));
                ps.setString(4, product.optString("type", "daily_susu"));
                ps.setLong(5, product.getLong("contribution_amount"));
                ps.setInt(6, product.optInt("cycle_length_days", 31));
                ps.setString(7, product.getString("commission_type"));
                ps.setLong(8, product.optLong("commission_value", 0));
                ps.setInt(9, product.optInt("interest_rate_bps", 0));
                if (product.isNull("par_value") || !product.has("par_value")) {
                    ps.setNull(10, java.sql.Types.BIGINT);
                } else {
                    ps.setLong(10, product.optLong("par_value", 0));
                }
                ps.setInt(11, product.optBoolean("is_active", true) ? 1 : 0);
                ps.setString(12, now);
                ps.setString(13, now);
                ps.executeUpdate();
            }
        }
    }

    private void bindProduct(PreparedStatement ps, JSONObject product) throws SQLException {
        ps.setString(1, product.getString("name"));
        ps.setString(2, product.optString("code", null));
        ps.setString(3, product.optString("type", "daily_susu"));
        ps.setLong(4, product.getLong("contribution_amount"));
        ps.setInt(5, product.optInt("cycle_length_days", 31));
        ps.setString(6, product.getString("commission_type"));
        ps.setLong(7, product.optLong("commission_value", 0));
        ps.setInt(8, product.optInt("interest_rate_bps", 0));
        if (product.isNull("par_value") || !product.has("par_value")) {
            ps.setNull(9, java.sql.Types.BIGINT);
        } else {
            ps.setLong(9, product.optLong("par_value", 0));
        }
        ps.setInt(10, product.optBoolean("is_active", true) ? 1 : 0);
        ps.setString(11, Instant.now().toString());
    }

    public SavingsProduct getOrCreateDefault() throws SQLException {
        for (SavingsProduct product : findActive()) {
            if (product.isDailySusu()) {
                return product;
            }
        }

        try (Connection conn = DatabaseConnection.getConnection()) {
            String id = UUID.randomUUID().toString();
            String now = Instant.now().toString();
            String sql = "INSERT INTO savings_products (id, name, code, type, contribution_amount,"
                    + " cycle_length_days, commission_type, commission_value, is_active, created_at, updated_at)"
                    + " VALUES (?,?,?,?,?,?,?,?,1,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, "Daily Susu");
                ps.setString(3, "DS-001");
                ps.setString(4, "daily_susu");
                ps.setLong(5, 500); // GHS 5.00
                ps.setInt(6, 31);
                ps.setString(7, CommissionType.FIRST_CONTRIBUTION_PER_CYCLE.value());
                ps.setLong(8, 0);
                ps.setString(9, now);
                ps.setString(10, now);
                ps.executeUpdate();
            }
        }

        return findActive().get(0);
    }

    /** Every product, active or not — the management screen's listing. */
    public List<SavingsProduct> findAll() throws SQLException {
        List<SavingsProduct> products = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM savings_products ORDER BY name");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                products.add(map(rs));
            }
        }
        return products;
    }

    /** Creates a product from the management screen. Returns the stored row. */
    public SavingsProduct create(SavingsProduct product) throws SQLException {
        String id = UUID.randomUUID().toString();
        String now = Instant.now().toString();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "INSERT INTO savings_products (id, name, code, type, contribution_amount,"
                     + " cycle_length_days, commission_type, commission_value, early_withdrawal_penalty_bps,"
                     + " interest_rate_bps, par_value, is_active, created_at, updated_at)"
                     + " VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,?)")) {
            ps.setString(1, id);
            ps.setString(2, product.getName());
            ps.setString(3, product.getCode());
            ps.setString(4, product.getType());
            ps.setLong(5, product.getContributionAmount());
            ps.setInt(6, product.getCycleLengthDays());
            ps.setString(7, product.getCommissionType().value());
            ps.setLong(8, product.getCommissionValue());
            ps.setInt(9, product.getEarlyWithdrawalPenaltyBps());
            ps.setInt(10, product.getInterestRateBps());
            if (product.getParValue() != null) {
                ps.setLong(11, product.getParValue());
            } else {
                ps.setNull(11, java.sql.Types.BIGINT);
            }
            ps.setString(12, now);
            ps.setString(13, now);
            ps.executeUpdate();
        }
        return findById(id);
    }

    /** Updates a product from the management screen's edit form. */
    public void update(SavingsProduct product) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE savings_products SET name = ?, code = ?, type = ?, contribution_amount = ?,"
                     + " cycle_length_days = ?, commission_type = ?, commission_value = ?, interest_rate_bps = ?,"
                     + " par_value = ?, updated_at = ? WHERE id = ?")) {
            ps.setString(1, product.getName());
            ps.setString(2, product.getCode());
            ps.setString(3, product.getType());
            ps.setLong(4, product.getContributionAmount());
            ps.setInt(5, product.getCycleLengthDays());
            ps.setString(6, product.getCommissionType().value());
            ps.setLong(7, product.getCommissionValue());
            ps.setInt(8, product.getInterestRateBps());
            if (product.getParValue() != null) {
                ps.setLong(9, product.getParValue());
            } else {
                ps.setNull(9, java.sql.Types.BIGINT);
            }
            ps.setString(10, Instant.now().toString());
            ps.setString(11, product.getId());
            ps.executeUpdate();
        }
    }

    public void setActive(String id, boolean active) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE savings_products SET is_active = ?, updated_at = ? WHERE id = ?")) {
            ps.setInt(1, active ? 1 : 0);
            ps.setString(2, Instant.now().toString());
            ps.setString(3, id);
            ps.executeUpdate();
        }
    }

    public List<SavingsProduct> findActive() throws SQLException {
        List<SavingsProduct> products = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM savings_products WHERE is_active = 1 ORDER BY name");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                products.add(map(rs));
            }
        }
        return products;
    }

    public SavingsProduct findById(String id) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection()) {
            return findById(conn, id);
        }
    }

    private SavingsProduct findById(Connection conn, String id) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement("SELECT * FROM savings_products WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? map(rs) : null;
            }
        }
    }

    private SavingsProduct map(ResultSet rs) throws SQLException {
        SavingsProduct product = new SavingsProduct();
        product.setId(rs.getString("id"));
        product.setName(rs.getString("name"));
        product.setCode(rs.getString("code"));
        product.setType(rs.getString("type"));
        product.setContributionAmount(rs.getLong("contribution_amount"));
        product.setCycleLengthDays(rs.getInt("cycle_length_days"));
        product.setCommissionType(CommissionType.fromValue(rs.getString("commission_type")));
        product.setCommissionValue(rs.getLong("commission_value"));
        product.setEarlyWithdrawalPenaltyBps(rs.getInt("early_withdrawal_penalty_bps"));
        product.setInterestRateBps(rs.getInt("interest_rate_bps"));
        long parValue = rs.getLong("par_value");
        product.setParValue(rs.wasNull() ? null : parValue);
        product.setActive(rs.getInt("is_active") != 0);
        return product;
    }

    /**
     * Lazily provisions a single target-savings product, same spirit as
     * {@link #getOrCreateDefault()} — standalone offices don't get a full
     * product-management screen, just the two products they actually need.
     */
    public SavingsProduct getOrCreateDefaultTarget() throws SQLException {
        for (SavingsProduct product : findActive()) {
            if (product.isTarget()) {
                return product;
            }
        }

        try (Connection conn = DatabaseConnection.getConnection()) {
            String id = UUID.randomUUID().toString();
            String now = Instant.now().toString();
            String sql = "INSERT INTO savings_products (id, name, code, type, contribution_amount,"
                    + " cycle_length_days, commission_type, commission_value, early_withdrawal_penalty_bps,"
                    + " is_active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,1,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, "Target Savings");
                ps.setString(3, "TS-001");
                ps.setString(4, SavingsProduct.TYPE_TARGET);
                ps.setLong(5, 500); // GHS 5.00 default contribution
                ps.setInt(6, 31);
                ps.setString(7, CommissionType.FLAT_PER_CYCLE.value());
                ps.setLong(8, 0);
                ps.setInt(9, 1000); // 10% early-withdrawal penalty
                ps.setString(10, now);
                ps.setString(11, now);
                ps.executeUpdate();
            }
            return findById(conn, id);
        }
    }

    /**
     * Lazily provisions a single fixed-deposit product, same spirit as
     * {@link #getOrCreateDefaultTarget()}. The 10% p.a. rate is an arbitrary
     * placeholder — no existing convention to copy from — adjustable via the
     * Products screen.
     */
    public SavingsProduct getOrCreateDefaultFixedDeposit() throws SQLException {
        for (SavingsProduct product : findActive()) {
            if (product.isFixedDeposit()) {
                return product;
            }
        }

        try (Connection conn = DatabaseConnection.getConnection()) {
            String id = UUID.randomUUID().toString();
            String now = Instant.now().toString();
            String sql = "INSERT INTO savings_products (id, name, code, type, contribution_amount,"
                    + " cycle_length_days, commission_type, commission_value, interest_rate_bps,"
                    + " is_active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,1,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, "Fixed Deposit");
                ps.setString(3, "FD-001");
                ps.setString(4, SavingsProduct.TYPE_FIXED_DEPOSIT);
                ps.setLong(5, 0);
                ps.setInt(6, 31);
                ps.setString(7, CommissionType.FLAT_PER_CYCLE.value());
                ps.setLong(8, 0);
                ps.setInt(9, 1000); // 10% p.a. placeholder rate
                ps.setString(10, now);
                ps.setString(11, now);
                ps.executeUpdate();
            }
            return findById(conn, id);
        }
    }

    /**
     * Lazily provisions a single shares product, same spirit as
     * {@link #getOrCreateDefaultTarget()}. GHS 10.00/share is an arbitrary
     * placeholder par value, adjustable via the Products screen.
     */
    public SavingsProduct getOrCreateDefaultShares() throws SQLException {
        for (SavingsProduct product : findActive()) {
            if (product.isShares()) {
                return product;
            }
        }

        try (Connection conn = DatabaseConnection.getConnection()) {
            String id = UUID.randomUUID().toString();
            String now = Instant.now().toString();
            String sql = "INSERT INTO savings_products (id, name, code, type, contribution_amount,"
                    + " cycle_length_days, commission_type, commission_value, par_value,"
                    + " is_active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,1,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, "Shares");
                ps.setString(3, "SH-001");
                ps.setString(4, SavingsProduct.TYPE_SHARES);
                ps.setLong(5, 0);
                ps.setInt(6, 31);
                ps.setString(7, CommissionType.FLAT_PER_CYCLE.value());
                ps.setLong(8, 0);
                ps.setLong(9, 1000); // GHS 10.00/share placeholder par value
                ps.setString(10, now);
                ps.setString(11, now);
                ps.executeUpdate();
            }
            return findById(conn, id);
        }
    }
}
