package service;

import db.DatabaseConnection;
import enums.GroupLoanStatus;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import models.LoanGroup;
import models.LoanGroupMember;

/**
 * Loan group roster: create, add/remove members. Unlike susu Group (rotation
 * freezes membership post-activation), a loan group has no such freeze —
 * members can be added/removed anytime, except while jointly liable on a
 * currently-disbursed, unclosed group loan. A parity port of the backend's
 * {@code App\Actions\LoanGroups\*Action} classes. Pure local roster — no
 * outbox ops, mirrors the backend's read-only {@code /loan-groups} API
 * (roster changes happen via Filament web only).
 */
public class LoanGroupService {

    private final CustomerService customerService = new CustomerService();

    public LoanGroup create(String name, String code, String createdBy) throws SQLException {
        String id = UUID.randomUUID().toString();
        String now = Instant.now().toString();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "INSERT INTO loan_groups (id, created_by, name, code, is_active,"
                     + " created_at, updated_at) VALUES (?,?,?,?,1,?,?)")) {
            ps.setString(1, id);
            ps.setString(2, createdBy);
            ps.setString(3, name);
            ps.setString(4, code);
            ps.setString(5, now);
            ps.setString(6, now);
            ps.executeUpdate();
        }
        return findById(id);
    }

    public LoanGroupMember addMember(String loanGroupId, String customerId) throws SQLException {
        if (findMembers(loanGroupId).stream()
                .anyMatch(m -> m.getCustomerId().equals(customerId) && "active".equals(m.getStatus()))) {
            throw new IllegalArgumentException("This customer is already a member of the loan group.");
        }

        String id = UUID.randomUUID().toString();
        String now = Instant.now().toString();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "INSERT INTO loan_group_members (id, loan_group_id, customer_id, status, joined_at,"
                     + " created_at, updated_at) VALUES (?,?,?,?,?,?,?)")) {
            ps.setString(1, id);
            ps.setString(2, loanGroupId);
            ps.setString(3, customerId);
            ps.setString(4, "active");
            ps.setString(5, now);
            ps.setString(6, now);
            ps.setString(7, now);
            ps.executeUpdate();
        }
        return findMemberById(id);
    }

    public LoanGroupMember removeMember(String memberId) throws SQLException {
        String openLoanStatus = openLoanStatus(memberId);
        if (GroupLoanStatus.ACTIVE.value().equals(openLoanStatus)) {
            throw new IllegalStateException(
                    "This member cannot be removed while they have an active loan in the group.");
        }
        if (GroupLoanStatus.DRAFT.value().equals(openLoanStatus)) {
            throw new IllegalStateException(
                    "This member has a loan awaiting activation. Cancel it before removing the member.");
        }

        String now = Instant.now().toString();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE loan_group_members SET status = ?, left_at = ?, updated_at = ? WHERE id = ?")) {
            ps.setString(1, "left");
            ps.setString(2, now);
            ps.setString(3, now);
            ps.setString(4, memberId);
            ps.executeUpdate();
        }
        return findMemberById(memberId);
    }

    /** Re-activates a member who previously left the roster (used when re-issuing them a loan). */
    public LoanGroupMember reactivateMember(String memberId) throws SQLException {
        String now = Instant.now().toString();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE loan_group_members SET status = 'active', left_at = NULL, joined_at = ?,"
                     + " updated_at = ? WHERE id = ?")) {
            ps.setString(1, now);
            ps.setString(2, now);
            ps.setString(3, memberId);
            ps.executeUpdate();
        }
        return findMemberById(memberId);
    }

    /** Status of the member's draft or active loan, or null when they have none (mirrors the server). */
    private String openLoanStatus(String memberId) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT status FROM group_loans WHERE loan_group_member_id = ? AND status IN (?, ?) LIMIT 1")) {
            ps.setString(1, memberId);
            ps.setString(2, GroupLoanStatus.DRAFT.value());
            ps.setString(3, GroupLoanStatus.ACTIVE.value());
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? rs.getString("status") : null;
            }
        }
    }

    public LoanGroup setActive(String id, boolean active) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE loan_groups SET is_active = ?, updated_at = ? WHERE id = ?")) {
            ps.setInt(1, active ? 1 : 0);
            ps.setString(2, Instant.now().toString());
            ps.setString(3, id);
            ps.executeUpdate();
        }
        return findById(id);
    }

    public List<LoanGroup> findAll() throws SQLException {
        List<LoanGroup> groups = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM loan_groups WHERE deleted_at IS NULL ORDER BY name");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                groups.add(map(rs));
            }
        }
        for (LoanGroup group : groups) {
            group.setMembers(findMembers(group.getId()));
        }
        return groups;
    }

    public LoanGroup findById(String id) throws SQLException {
        LoanGroup group;
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM loan_groups WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                if (!rs.next()) {
                    return null;
                }
                group = map(rs);
            }
        }
        group.setMembers(findMembers(id));
        return group;
    }

    public List<LoanGroupMember> findMembers(String loanGroupId) throws SQLException {
        List<LoanGroupMember> members = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM loan_group_members WHERE loan_group_id = ? ORDER BY joined_at")) {
            ps.setString(1, loanGroupId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    members.add(mapMember(rs));
                }
            }
        }
        for (LoanGroupMember member : members) {
            member.setCustomer(customerService.findById(member.getCustomerId()));
        }
        return members;
    }

    public LoanGroupMember findMemberById(String id) throws SQLException {
        LoanGroupMember member;
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM loan_group_members WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                if (!rs.next()) {
                    return null;
                }
                member = mapMember(rs);
            }
        }
        member.setCustomer(customerService.findById(member.getCustomerId()));
        return member;
    }

    private LoanGroup map(ResultSet rs) throws SQLException {
        LoanGroup group = new LoanGroup();
        group.setId(rs.getString("id"));
        group.setCreatedBy(rs.getString("created_by"));
        group.setName(rs.getString("name"));
        group.setCode(rs.getString("code"));
        group.setActive(rs.getInt("is_active") != 0);
        return group;
    }

    private LoanGroupMember mapMember(ResultSet rs) throws SQLException {
        LoanGroupMember member = new LoanGroupMember();
        member.setId(rs.getString("id"));
        member.setLoanGroupId(rs.getString("loan_group_id"));
        member.setCustomerId(rs.getString("customer_id"));
        member.setStatus(rs.getString("status"));
        member.setJoinedAt(Instant.parse(rs.getString("joined_at")));
        String leftAt = rs.getString("left_at");
        member.setLeftAt(leftAt != null ? Instant.parse(leftAt) : null);
        return member;
    }
}
