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

/**
 * Susu product catalogue. Standalone offices are typically single-product
 * ("Daily Susu"), so a default is lazily provisioned on first use rather
 * than requiring a setup step — {@link #getOrCreateDefault()} is what the
 * account-opening screen calls.
 */
public class SavingsProductService {

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
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM savings_products WHERE id = ?")) {
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
        product.setActive(rs.getInt("is_active") != 0);
        return product;
    }
}
