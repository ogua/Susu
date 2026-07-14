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
                        + " cycle_length_days = ?, commission_type = ?, commission_value = ?, is_active = ?,"
                        + " updated_at = ? WHERE id = ?";
                try (PreparedStatement ps = conn.prepareStatement(sql)) {
                    bindProduct(ps, product);
                    ps.setString(10, id);
                    ps.executeUpdate();
                }
                return;
            }

            String now = Instant.now().toString();
            String sql = "INSERT INTO savings_products (id, name, code, type, contribution_amount,"
                    + " cycle_length_days, commission_type, commission_value, is_active, created_at, updated_at)"
                    + " VALUES (?,?,?,?,?,?,?,?,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, product.getString("name"));
                ps.setString(3, product.optString("code", null));
                ps.setString(4, product.optString("type", "daily_susu"));
                ps.setLong(5, product.getLong("contribution_amount"));
                ps.setInt(6, product.optInt("cycle_length_days", 31));
                ps.setString(7, product.getString("commission_type"));
                ps.setLong(8, product.optLong("commission_value", 0));
                ps.setInt(9, product.optBoolean("is_active", true) ? 1 : 0);
                ps.setString(10, now);
                ps.setString(11, now);
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
        ps.setInt(8, product.optBoolean("is_active", true) ? 1 : 0);
        ps.setString(9, Instant.now().toString());
    }

    public SavingsProduct getOrCreateDefault() throws SQLException {
        List<SavingsProduct> active = findActive();
        if (!active.isEmpty()) {
            return active.get(0);
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
}
