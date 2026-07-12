package service;

import db.DatabaseConnection;
import enums.AccountStatus;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import models.Customer;
import org.json.JSONObject;

/** Customer registration and lookup — mirrors App\Actions\Customers\*Action. */
public class CustomerService {

    private final OutboxService outbox = new OutboxService();

    public Customer register(Customer customer, String registeredBy, String clientReference) throws SQLException {
        String effectiveClientReference = clientReference != null ? clientReference : UUID.randomUUID().toString();

        Customer existing = findByClientReference(effectiveClientReference);
        if (existing != null) {
            return existing;
        }

        Customer created;
        try (Connection conn = DatabaseConnection.getConnection()) {
            // The local id doubles as client_reference (see SavingsAccountService.open for
            // why): the backend's CreateCustomerAction uses it as the row's own id, so this
            // customer's identity matches across desktop and server once synced.
            String id = effectiveClientReference;
            String now = Instant.now().toString();
            String code = nextCustomerCode(conn);

            String sql = "INSERT INTO customers (id, customer_code, first_name, last_name, phone, gender,"
                    + " date_of_birth, id_type, id_number, next_of_kin_name, next_of_kin_phone,"
                    + " next_of_kin_relationship, address, status, client_reference, registered_by,"
                    + " created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, id);
                ps.setString(2, code);
                ps.setString(3, customer.getFirstName());
                ps.setString(4, customer.getLastName());
                ps.setString(5, customer.getPhone());
                ps.setString(6, customer.getGender());
                ps.setString(7, customer.getDateOfBirth());
                ps.setString(8, customer.getIdType() != null ? customer.getIdType() : "ghana_card");
                ps.setString(9, customer.getIdNumber());
                ps.setString(10, customer.getNextOfKinName());
                ps.setString(11, customer.getNextOfKinPhone());
                ps.setString(12, customer.getNextOfKinRelationship());
                ps.setString(13, customer.getAddress());
                ps.setString(14, AccountStatus.ACTIVE.value());
                ps.setString(15, effectiveClientReference);
                ps.setString(16, registeredBy);
                ps.setString(17, now);
                ps.setString(18, now);
                ps.executeUpdate();
            }

            created = findById(conn, id);
        }

        outbox.enqueueIfHybrid("customer.register", new JSONObject()
                .put("first_name", customer.getFirstName())
                .put("last_name", customer.getLastName())
                .put("phone", customer.getPhone())
                .put("gender", customer.getGender())
                .put("date_of_birth", customer.getDateOfBirth())
                .put("id_type", customer.getIdType())
                .put("id_number", customer.getIdNumber())
                .put("next_of_kin_name", customer.getNextOfKinName())
                .put("next_of_kin_phone", customer.getNextOfKinPhone())
                .put("next_of_kin_relationship", customer.getNextOfKinRelationship())
                .put("address", customer.getAddress())
                .put("client_reference", effectiveClientReference));

        return created;
    }

    public Customer findById(String id) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection()) {
            return findById(conn, id);
        }
    }

    public List<Customer> search(String query) throws SQLException {
        List<Customer> results = new ArrayList<>();
        String sql = (query == null || query.isBlank())
                ? "SELECT * FROM customers WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 200"
                : "SELECT * FROM customers WHERE deleted_at IS NULL AND"
                  + " (first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR customer_code LIKE ?)"
                  + " ORDER BY created_at DESC LIMIT 200";

        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(sql)) {
            if (query != null && !query.isBlank()) {
                String like = "%" + query + "%";
                ps.setString(1, like);
                ps.setString(2, like);
                ps.setString(3, like);
                ps.setString(4, like);
            }
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    results.add(map(rs));
                }
            }
        }
        return results;
    }

    private Customer findByClientReference(String clientReference) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM customers WHERE client_reference = ?")) {
            ps.setString(1, clientReference);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? map(rs) : null;
            }
        }
    }

    private Customer findById(Connection conn, String id) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement("SELECT * FROM customers WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? map(rs) : null;
            }
        }
    }

    /** G7 numbering: C-{sequence}, opaque and unique — UUIDs remain the real identity. */
    private String nextCustomerCode(Connection conn) throws SQLException {
        int sequence;
        try (PreparedStatement ps = conn.prepareStatement("SELECT COUNT(*) FROM customers");
             ResultSet rs = ps.executeQuery()) {
            rs.next();
            sequence = rs.getInt(1) + 1;
        }

        String code;
        do {
            code = "C-" + String.format("%05d", sequence);
            sequence++;
        } while (codeExists(conn, code));

        return code;
    }

    private boolean codeExists(Connection conn, String code) throws SQLException {
        try (PreparedStatement ps = conn.prepareStatement(
                "SELECT 1 FROM customers WHERE customer_code = ?")) {
            ps.setString(1, code);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next();
            }
        }
    }

    private Customer map(ResultSet rs) throws SQLException {
        Customer customer = new Customer();
        customer.setId(rs.getString("id"));
        customer.setCustomerCode(rs.getString("customer_code"));
        customer.setFirstName(rs.getString("first_name"));
        customer.setLastName(rs.getString("last_name"));
        customer.setPhone(rs.getString("phone"));
        customer.setGender(rs.getString("gender"));
        customer.setDateOfBirth(rs.getString("date_of_birth"));
        customer.setIdType(rs.getString("id_type"));
        customer.setIdNumber(rs.getString("id_number"));
        customer.setNextOfKinName(rs.getString("next_of_kin_name"));
        customer.setNextOfKinPhone(rs.getString("next_of_kin_phone"));
        customer.setNextOfKinRelationship(rs.getString("next_of_kin_relationship"));
        customer.setAddress(rs.getString("address"));
        customer.setStatus(AccountStatus.fromValue(rs.getString("status")));
        customer.setClientReference(rs.getString("client_reference"));
        customer.setRegisteredBy(rs.getString("registered_by"));
        return customer;
    }
}
