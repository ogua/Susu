package service;

import db.DatabaseConnection;
import enums.AccountStatus;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Types;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import models.Customer;
import models.CustomerBeneficiary;
import models.CustomerFamilyMember;
import models.CustomerIdentification;
import org.json.JSONArray;
import org.json.JSONObject;

/** Customer registration and lookup — mirrors App\Actions\Customers\*Action. */
public class CustomerService {

    private final OutboxService outbox = new OutboxService();

    public Customer register(Customer customer, String registeredBy, String clientReference) throws SQLException {
        return register(customer, registeredBy, clientReference, List.of(), List.of(), List.of());
    }

    /**
     * Full eBanQR-depth registration: persists the customer plus any
     * identification/beneficiary/family-member rows collected on the
     * registration screen, all on the same connection. There is no
     * customer.update sync op on the backend yet, so this is the only
     * write path for these child rows — editing them later isn't
     * supported until that op exists server-side.
     */
    public Customer register(Customer customer, String registeredBy, String clientReference,
                              List<CustomerIdentification> identifications,
                              List<CustomerBeneficiary> beneficiaries,
                              List<CustomerFamilyMember> familyMembers) throws SQLException {
        String effectiveClientReference = clientReference != null ? clientReference : UUID.randomUUID().toString();

        Customer existing = findByClientReference(effectiveClientReference);
        if (existing != null) {
            return existing;
        }

        Customer created;
        List<CustomerIdentification> insertedIdentifications = new ArrayList<>();
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
                    + " client_type, external_id, place_of_birth, nationality, email, city_town,"
                    + " state_region, country, digital_address, latitude, longitude, marital_status,"
                    + " spouse_name, spouse_date_of_birth, spouse_occupation, has_past_loan,"
                    + " past_loan_institution, spouse_employer_name, spouse_employer_address,"
                    + " spouse_employer_town, spouse_employer_county, spouse_employer_region, religion,"
                    + " business_name, business_phone, business_tin, business_line, business_structure,"
                    + " business_start_date, business_income_level, business_address, business_town,"
                    + " business_county, business_region, business_latitude, business_longitude, tin,"
                    + " other_names, occupation, job_title, country_of_residence, residence_permit,"
                    + " residency_status, assigned_agent_id, created_at, updated_at)"
                    + " VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"
                    + "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                int i = 1;
                ps.setString(i++, id);
                ps.setString(i++, code);
                ps.setString(i++, customer.getFirstName());
                ps.setString(i++, customer.getLastName());
                ps.setString(i++, customer.getPhone());
                ps.setString(i++, customer.getGender());
                ps.setString(i++, customer.getDateOfBirth());
                ps.setString(i++, customer.getIdType() != null ? customer.getIdType() : "ghana_card");
                ps.setString(i++, customer.getIdNumber());
                ps.setString(i++, customer.getNextOfKinName());
                ps.setString(i++, customer.getNextOfKinPhone());
                ps.setString(i++, customer.getNextOfKinRelationship());
                ps.setString(i++, customer.getAddress());
                ps.setString(i++, AccountStatus.ACTIVE.value());
                ps.setString(i++, effectiveClientReference);
                ps.setString(i++, registeredBy);
                ps.setString(i++, customer.getClientType() != null ? customer.getClientType() : "individual");
                ps.setString(i++, customer.getExternalId());
                ps.setString(i++, customer.getPlaceOfBirth());
                ps.setString(i++, customer.getNationality());
                ps.setString(i++, customer.getEmail());
                ps.setString(i++, customer.getCityTown());
                ps.setString(i++, customer.getStateRegion());
                ps.setString(i++, customer.getCountry());
                ps.setString(i++, customer.getDigitalAddress());
                setNullableDouble(ps, i++, customer.getLatitude());
                setNullableDouble(ps, i++, customer.getLongitude());
                ps.setString(i++, customer.getMaritalStatus());
                ps.setString(i++, customer.getSpouseName());
                ps.setString(i++, customer.getSpouseDateOfBirth());
                ps.setString(i++, customer.getSpouseOccupation());
                setNullableBoolean(ps, i++, customer.getHasPastLoan());
                ps.setString(i++, customer.getPastLoanInstitution());
                ps.setString(i++, customer.getSpouseEmployerName());
                ps.setString(i++, customer.getSpouseEmployerAddress());
                ps.setString(i++, customer.getSpouseEmployerTown());
                ps.setString(i++, customer.getSpouseEmployerCounty());
                ps.setString(i++, customer.getSpouseEmployerRegion());
                ps.setString(i++, customer.getReligion());
                ps.setString(i++, customer.getBusinessName());
                ps.setString(i++, customer.getBusinessPhone());
                ps.setString(i++, customer.getBusinessTin());
                ps.setString(i++, customer.getBusinessLine());
                ps.setString(i++, customer.getBusinessStructure());
                ps.setString(i++, customer.getBusinessStartDate());
                ps.setString(i++, customer.getBusinessIncomeLevel());
                ps.setString(i++, customer.getBusinessAddress());
                ps.setString(i++, customer.getBusinessTown());
                ps.setString(i++, customer.getBusinessCounty());
                ps.setString(i++, customer.getBusinessRegion());
                setNullableDouble(ps, i++, customer.getBusinessLatitude());
                setNullableDouble(ps, i++, customer.getBusinessLongitude());
                ps.setString(i++, customer.getTin());
                ps.setString(i++, customer.getOtherNames());
                ps.setString(i++, customer.getOccupation());
                ps.setString(i++, customer.getJobTitle());
                ps.setString(i++, customer.getCountryOfResidence());
                ps.setString(i++, customer.getResidencePermit());
                ps.setString(i++, customer.getResidencyStatus());
                ps.setString(i++, customer.getAssignedAgentId());
                ps.setString(i++, now);
                ps.setString(i, now);
                ps.executeUpdate();
            }

            for (CustomerIdentification identification : identifications) {
                insertedIdentifications.add(insertIdentification(conn, id, identification, now));
            }
            for (CustomerBeneficiary beneficiary : beneficiaries) {
                insertBeneficiary(conn, id, beneficiary, now);
            }
            for (CustomerFamilyMember familyMember : familyMembers) {
                insertFamilyMember(conn, id, familyMember, now);
            }

            applyPrimaryIdentification(conn, id, insertedIdentifications);

            created = findById(conn, id);
        }

        JSONObject payload = new JSONObject()
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
                .put("client_reference", effectiveClientReference)
                .put("client_type", customer.getClientType())
                .put("external_id", customer.getExternalId())
                .put("place_of_birth", customer.getPlaceOfBirth())
                .put("nationality", customer.getNationality())
                .put("email", customer.getEmail())
                .put("city_town", customer.getCityTown())
                .put("state_region", customer.getStateRegion())
                .put("country", customer.getCountry())
                .put("digital_address", customer.getDigitalAddress())
                .put("latitude", customer.getLatitude())
                .put("longitude", customer.getLongitude())
                .put("marital_status", customer.getMaritalStatus())
                .put("spouse_name", customer.getSpouseName())
                .put("spouse_date_of_birth", customer.getSpouseDateOfBirth())
                .put("spouse_occupation", customer.getSpouseOccupation())
                .put("has_past_loan", customer.getHasPastLoan())
                .put("past_loan_institution", customer.getPastLoanInstitution())
                .put("spouse_employer_name", customer.getSpouseEmployerName())
                .put("spouse_employer_address", customer.getSpouseEmployerAddress())
                .put("spouse_employer_town", customer.getSpouseEmployerTown())
                .put("spouse_employer_county", customer.getSpouseEmployerCounty())
                .put("spouse_employer_region", customer.getSpouseEmployerRegion())
                .put("religion", customer.getReligion())
                .put("business_name", customer.getBusinessName())
                .put("business_phone", customer.getBusinessPhone())
                .put("business_tin", customer.getBusinessTin())
                .put("business_line", customer.getBusinessLine())
                .put("business_structure", customer.getBusinessStructure())
                .put("business_start_date", customer.getBusinessStartDate())
                .put("business_income_level", customer.getBusinessIncomeLevel())
                .put("business_address", customer.getBusinessAddress())
                .put("business_town", customer.getBusinessTown())
                .put("business_county", customer.getBusinessCounty())
                .put("business_region", customer.getBusinessRegion())
                .put("business_latitude", customer.getBusinessLatitude())
                .put("business_longitude", customer.getBusinessLongitude())
                .put("tin", customer.getTin())
                .put("other_names", customer.getOtherNames())
                .put("occupation", customer.getOccupation())
                .put("job_title", customer.getJobTitle())
                .put("country_of_residence", customer.getCountryOfResidence())
                .put("residence_permit", customer.getResidencePermit())
                .put("residency_status", customer.getResidencyStatus())
                .put("assigned_agent_id", customer.getAssignedAgentId());

        if (!insertedIdentifications.isEmpty()) {
            JSONArray identificationsJson = new JSONArray();
            for (CustomerIdentification identification : insertedIdentifications) {
                identificationsJson.put(new JSONObject()
                        .put("id", identification.getId())
                        .put("id_type", identification.getIdType())
                        .put("id_number", identification.getIdNumber())
                        .put("issue_date", identification.getIssueDate())
                        .put("expiry_date", identification.getExpiryDate())
                        .put("description", identification.getDescription())
                        .put("is_primary", identification.getIsPrimary()));
            }
            payload.put("identifications", identificationsJson);
        }
        if (!beneficiaries.isEmpty()) {
            JSONArray beneficiariesJson = new JSONArray();
            for (CustomerBeneficiary beneficiary : beneficiaries) {
                beneficiariesJson.put(new JSONObject()
                        .put("id", beneficiary.getId())
                        .put("name", beneficiary.getName())
                        .put("relationship", beneficiary.getRelationship())
                        .put("amount_of_legacy", beneficiary.getAmountOfLegacy())
                        .put("phone", beneficiary.getPhone())
                        .put("address", beneficiary.getAddress())
                        .put("town", beneficiary.getTown())
                        .put("county", beneficiary.getCounty())
                        .put("state_region", beneficiary.getStateRegion()));
            }
            payload.put("beneficiaries", beneficiariesJson);
        }
        if (!familyMembers.isEmpty()) {
            JSONArray familyMembersJson = new JSONArray();
            for (CustomerFamilyMember familyMember : familyMembers) {
                familyMembersJson.put(new JSONObject()
                        .put("id", familyMember.getId())
                        .put("name", familyMember.getName())
                        .put("relationship", familyMember.getRelationship())
                        .put("contact_phone", familyMember.getContactPhone())
                        .put("occupation", familyMember.getOccupation()));
            }
            payload.put("family_members", familyMembersJson);
        }

        outbox.enqueueIfHybrid("customer.register", payload);

        return created;
    }

    private CustomerIdentification insertIdentification(Connection conn, String customerId,
            CustomerIdentification identification, String now) throws SQLException {
        String id = UUID.randomUUID().toString();
        try (PreparedStatement ps = conn.prepareStatement(
                "INSERT INTO customer_identifications (id, customer_id, id_type, id_number, issue_date,"
                + " expiry_date, description, is_primary, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)")) {
            ps.setString(1, id);
            ps.setString(2, customerId);
            ps.setString(3, identification.getIdType() != null ? identification.getIdType() : "ghana_card");
            ps.setString(4, identification.getIdNumber());
            ps.setString(5, identification.getIssueDate());
            ps.setString(6, identification.getExpiryDate());
            ps.setString(7, identification.getDescription());
            ps.setInt(8, Boolean.TRUE.equals(identification.getIsPrimary()) ? 1 : 0);
            ps.setString(9, now);
            ps.setString(10, now);
            ps.executeUpdate();
        }
        identification.setId(id);
        identification.setCustomerId(customerId);
        return identification;
    }

    private void insertBeneficiary(Connection conn, String customerId, CustomerBeneficiary beneficiary, String now)
            throws SQLException {
        String id = UUID.randomUUID().toString();
        try (PreparedStatement ps = conn.prepareStatement(
                "INSERT INTO customer_beneficiaries (id, customer_id, name, relationship, amount_of_legacy,"
                + " phone, address, town, county, state_region, created_at, updated_at)"
                + " VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")) {
            ps.setString(1, id);
            ps.setString(2, customerId);
            ps.setString(3, beneficiary.getName());
            ps.setString(4, beneficiary.getRelationship());
            ps.setLong(5, beneficiary.getAmountOfLegacy());
            ps.setString(6, beneficiary.getPhone());
            ps.setString(7, beneficiary.getAddress());
            ps.setString(8, beneficiary.getTown());
            ps.setString(9, beneficiary.getCounty());
            ps.setString(10, beneficiary.getStateRegion());
            ps.setString(11, now);
            ps.setString(12, now);
            ps.executeUpdate();
        }
        beneficiary.setId(id);
        beneficiary.setCustomerId(customerId);
    }

    private void insertFamilyMember(Connection conn, String customerId, CustomerFamilyMember familyMember, String now)
            throws SQLException {
        String id = UUID.randomUUID().toString();
        try (PreparedStatement ps = conn.prepareStatement(
                "INSERT INTO customer_family_members (id, customer_id, name, relationship, contact_phone,"
                + " occupation, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)")) {
            ps.setString(1, id);
            ps.setString(2, customerId);
            ps.setString(3, familyMember.getName());
            ps.setString(4, familyMember.getRelationship());
            ps.setString(5, familyMember.getContactPhone());
            ps.setString(6, familyMember.getOccupation());
            ps.setString(7, now);
            ps.setString(8, now);
            ps.executeUpdate();
        }
        familyMember.setId(id);
        familyMember.setCustomerId(customerId);
    }

    /**
     * Mirrors whichever identification is flagged primary (or the first, if
     * none is flagged) onto the customer's legacy flat id_type/id_number
     * columns — mirrors the backend's applyPrimaryIdentification, so any
     * code still reading those columns directly (reports, CustomerDetailController)
     * keeps working. No-ops if no identifications were provided.
     */
    private void applyPrimaryIdentification(Connection conn, String customerId,
            List<CustomerIdentification> identifications) throws SQLException {
        if (identifications.isEmpty()) {
            return;
        }

        CustomerIdentification primary = identifications.stream()
                .filter(row -> Boolean.TRUE.equals(row.getIsPrimary()))
                .findFirst()
                .orElse(identifications.get(0));

        try (PreparedStatement ps = conn.prepareStatement(
                "UPDATE customers SET id_type = ?, id_number = ? WHERE id = ?")) {
            ps.setString(1, primary.getIdType());
            ps.setString(2, primary.getIdNumber());
            ps.setString(3, customerId);
            ps.executeUpdate();
        }
    }

    public List<CustomerIdentification> findIdentifications(String customerId) throws SQLException {
        List<CustomerIdentification> results = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM customer_identifications WHERE customer_id = ? ORDER BY created_at")) {
            ps.setString(1, customerId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    CustomerIdentification identification = new CustomerIdentification();
                    identification.setId(rs.getString("id"));
                    identification.setCustomerId(rs.getString("customer_id"));
                    identification.setIdType(rs.getString("id_type"));
                    identification.setIdNumber(rs.getString("id_number"));
                    identification.setIssueDate(rs.getString("issue_date"));
                    identification.setExpiryDate(rs.getString("expiry_date"));
                    identification.setDescription(rs.getString("description"));
                    identification.setIsPrimary(rs.getInt("is_primary") == 1);
                    results.add(identification);
                }
            }
        }
        return results;
    }

    public List<CustomerBeneficiary> findBeneficiaries(String customerId) throws SQLException {
        List<CustomerBeneficiary> results = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM customer_beneficiaries WHERE customer_id = ? ORDER BY created_at")) {
            ps.setString(1, customerId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    CustomerBeneficiary beneficiary = new CustomerBeneficiary();
                    beneficiary.setId(rs.getString("id"));
                    beneficiary.setCustomerId(rs.getString("customer_id"));
                    beneficiary.setName(rs.getString("name"));
                    beneficiary.setRelationship(rs.getString("relationship"));
                    beneficiary.setAmountOfLegacy(rs.getLong("amount_of_legacy"));
                    beneficiary.setPhone(rs.getString("phone"));
                    beneficiary.setAddress(rs.getString("address"));
                    beneficiary.setTown(rs.getString("town"));
                    beneficiary.setCounty(rs.getString("county"));
                    beneficiary.setStateRegion(rs.getString("state_region"));
                    results.add(beneficiary);
                }
            }
        }
        return results;
    }

    public List<CustomerFamilyMember> findFamilyMembers(String customerId) throws SQLException {
        List<CustomerFamilyMember> results = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM customer_family_members WHERE customer_id = ? ORDER BY created_at")) {
            ps.setString(1, customerId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    CustomerFamilyMember familyMember = new CustomerFamilyMember();
                    familyMember.setId(rs.getString("id"));
                    familyMember.setCustomerId(rs.getString("customer_id"));
                    familyMember.setName(rs.getString("name"));
                    familyMember.setRelationship(rs.getString("relationship"));
                    familyMember.setContactPhone(rs.getString("contact_phone"));
                    familyMember.setOccupation(rs.getString("occupation"));
                    results.add(familyMember);
                }
            }
        }
        return results;
    }

    public Customer findById(String id) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection()) {
            return findById(conn, id);
        }
    }

    /** Search within one list segment (the web list's tabs). */
    public List<Customer> search(String query, enums.CustomerSegment segment) throws SQLException {
        enums.CustomerSegment effective = segment != null ? segment : enums.CustomerSegment.ALL;
        boolean filtered = query != null && !query.isBlank();
        String sql = "SELECT * FROM customers WHERE deleted_at IS NULL AND " + effective.condition()
                + (filtered ? " AND (first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR customer_code LIKE ?)" : "")
                + " ORDER BY created_at DESC LIMIT 500";

        List<Customer> results = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(sql)) {
            if (filtered) {
                String like = "%" + query + "%";
                for (int i = 1; i <= 4; i++) {
                    ps.setString(i, like);
                }
            }
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    results.add(map(rs));
                }
            }
        }
        return results;
    }

    /** Headline numbers for the list: per-segment counts, new this month, active savings held. */
    public record Overview(java.util.Map<enums.CustomerSegment, Integer> segments, int newThisMonth, long savingsBalance) {}

    public Overview overview() throws SQLException {
        java.util.Map<enums.CustomerSegment, Integer> counts = new java.util.EnumMap<>(enums.CustomerSegment.class);
        try (Connection conn = DatabaseConnection.getConnection()) {
            for (enums.CustomerSegment segment : enums.CustomerSegment.values()) {
                try (PreparedStatement ps = conn.prepareStatement(
                        "SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL AND " + segment.condition());
                     ResultSet rs = ps.executeQuery()) {
                    counts.put(segment, rs.next() ? rs.getInt(1) : 0);
                }
            }
            int newThisMonth;
            try (PreparedStatement ps = conn.prepareStatement(
                    "SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL AND created_at >= ?")) {
                ps.setString(1, java.time.LocalDate.now().withDayOfMonth(1).toString());
                try (ResultSet rs = ps.executeQuery()) {
                    newThisMonth = rs.next() ? rs.getInt(1) : 0;
                }
            }
            long savings;
            try (PreparedStatement ps = conn.prepareStatement(
                    "SELECT COALESCE(SUM(a.balance), 0) FROM savings_accounts a JOIN customers c ON c.id = a.customer_id"
                    + " WHERE a.status = 'active' AND c.deleted_at IS NULL");
                 ResultSet rs = ps.executeQuery()) {
                savings = rs.next() ? rs.getLong(1) : 0;
            }
            return new Overview(counts, newThisMonth, savings);
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

    private void setNullableDouble(PreparedStatement ps, int index, Double value) throws SQLException {
        if (value == null) {
            ps.setNull(index, Types.DOUBLE);
        } else {
            ps.setDouble(index, value);
        }
    }

    private void setNullableBoolean(PreparedStatement ps, int index, Boolean value) throws SQLException {
        if (value == null) {
            ps.setNull(index, Types.INTEGER);
        } else {
            ps.setInt(index, value ? 1 : 0);
        }
    }

    private Double getNullableDouble(ResultSet rs, String column) throws SQLException {
        double value = rs.getDouble(column);
        return rs.wasNull() ? null : value;
    }

    private Boolean getNullableBoolean(ResultSet rs, String column) throws SQLException {
        int value = rs.getInt(column);
        return rs.wasNull() ? null : value == 1;
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
        customer.setClientType(rs.getString("client_type"));
        customer.setExternalId(rs.getString("external_id"));
        customer.setPlaceOfBirth(rs.getString("place_of_birth"));
        customer.setNationality(rs.getString("nationality"));
        customer.setEmail(rs.getString("email"));
        customer.setCityTown(rs.getString("city_town"));
        customer.setStateRegion(rs.getString("state_region"));
        customer.setCountry(rs.getString("country"));
        customer.setDigitalAddress(rs.getString("digital_address"));
        customer.setLatitude(getNullableDouble(rs, "latitude"));
        customer.setLongitude(getNullableDouble(rs, "longitude"));
        customer.setMaritalStatus(rs.getString("marital_status"));
        customer.setSpouseName(rs.getString("spouse_name"));
        customer.setSpouseDateOfBirth(rs.getString("spouse_date_of_birth"));
        customer.setSpouseOccupation(rs.getString("spouse_occupation"));
        customer.setHasPastLoan(getNullableBoolean(rs, "has_past_loan"));
        customer.setPastLoanInstitution(rs.getString("past_loan_institution"));
        customer.setSpouseEmployerName(rs.getString("spouse_employer_name"));
        customer.setSpouseEmployerAddress(rs.getString("spouse_employer_address"));
        customer.setSpouseEmployerTown(rs.getString("spouse_employer_town"));
        customer.setSpouseEmployerCounty(rs.getString("spouse_employer_county"));
        customer.setSpouseEmployerRegion(rs.getString("spouse_employer_region"));
        customer.setReligion(rs.getString("religion"));
        customer.setBusinessName(rs.getString("business_name"));
        customer.setBusinessPhone(rs.getString("business_phone"));
        customer.setBusinessTin(rs.getString("business_tin"));
        customer.setBusinessLine(rs.getString("business_line"));
        customer.setBusinessStructure(rs.getString("business_structure"));
        customer.setBusinessStartDate(rs.getString("business_start_date"));
        customer.setBusinessIncomeLevel(rs.getString("business_income_level"));
        customer.setBusinessAddress(rs.getString("business_address"));
        customer.setBusinessTown(rs.getString("business_town"));
        customer.setBusinessCounty(rs.getString("business_county"));
        customer.setBusinessRegion(rs.getString("business_region"));
        customer.setBusinessLatitude(getNullableDouble(rs, "business_latitude"));
        customer.setBusinessLongitude(getNullableDouble(rs, "business_longitude"));
        customer.setTin(rs.getString("tin"));
        customer.setOtherNames(rs.getString("other_names"));
        customer.setOccupation(rs.getString("occupation"));
        customer.setJobTitle(rs.getString("job_title"));
        customer.setCountryOfResidence(rs.getString("country_of_residence"));
        customer.setResidencePermit(rs.getString("residence_permit"));
        customer.setResidencyStatus(rs.getString("residency_status"));
        customer.setAssignedAgentId(rs.getString("assigned_agent_id"));
        return customer;
    }
}
