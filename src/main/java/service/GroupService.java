package service;

import db.DatabaseConnection;
import enums.GroupRoundStatus;
import enums.GroupStatus;
import enums.PaymentMethod;
import enums.TransactionType;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import models.Group;
import models.GroupContribution;
import models.GroupMember;
import models.GroupRound;
import models.JournalEntry;
import org.json.JSONObject;

/**
 * ROSCA susu group lifecycle: add members (draft only) -> activate (locks
 * the rotation, generates every round upfront) -> record contributions ->
 * payout each round to its rotation-designated member. A disciplined,
 * version-stamped ({@link LedgerService#ENGINE_VERSION}) parity port of the
 * backend's {@code App\Actions\Groups\*Action} classes.
 */
public class GroupService {

    private final LedgerService ledger = new LedgerService();
    private final ChartOfAccounts chart = new ChartOfAccounts();
    private final CustomerService customerService = new CustomerService();
    private final OutboxService outbox = new OutboxService();

    public GroupMember addMember(String groupId, String customerId, int rotationPosition) throws SQLException {
        Group group = findById(groupId);
        if (group == null || group.getStatus() != GroupStatus.DRAFT) {
            throw new IllegalStateException("Members can only be added while the group is in draft.");
        }
        if (findMembers(groupId).stream().anyMatch(m -> m.getCustomerId().equals(customerId))) {
            throw new IllegalArgumentException("This customer is already a member of the group.");
        }
        if (findMembers(groupId).stream().anyMatch(m -> m.getRotationPosition() == rotationPosition)) {
            throw new IllegalArgumentException("This rotation position is already taken.");
        }

        String id = UUID.randomUUID().toString();
        String now = Instant.now().toString();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "INSERT INTO group_members (id, group_id, customer_id, rotation_position, status,"
                     + " joined_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)")) {
            ps.setString(1, id);
            ps.setString(2, groupId);
            ps.setString(3, customerId);
            ps.setInt(4, rotationPosition);
            ps.setString(5, "active");
            ps.setString(6, now);
            ps.setString(7, now);
            ps.setString(8, now);
            ps.executeUpdate();
        }
        return findMemberById(id);
    }

    public Group activate(String groupId) throws SQLException {
        Group group = findById(groupId);
        if (group == null || group.getStatus() != GroupStatus.DRAFT) {
            throw new IllegalStateException("Only draft groups can be activated.");
        }

        List<GroupMember> members = findMembers(groupId).stream()
                .filter(m -> "active".equals(m.getStatus()))
                .sorted((a, b) -> Integer.compare(a.getRotationPosition(), b.getRotationPosition()))
                .toList();
        if (members.size() < 2) {
            throw new IllegalStateException("A group needs at least 2 members to activate.");
        }
        for (int i = 0; i < members.size(); i++) {
            if (members.get(i).getRotationPosition() != i + 1) {
                throw new IllegalStateException("Rotation positions must be contiguous from 1 to the member count.");
            }
        }

        String liabilityAccountId = chart.groupLiability(group.getId(), group.getCode()).getId();
        long totalExpected = group.getContributionAmount() * members.size();
        Instant activatedAt = Instant.now();
        LocalDate dueDate = LocalDate.now();

        try (Connection conn = DatabaseConnection.getConnection()) {
            for (int i = 0; i < members.size(); i++) {
                int roundNumber = i + 1;
                dueDate = addPeriod(dueDate, group.getFrequency());

                String roundId = UUID.randomUUID().toString();
                String now = Instant.now().toString();
                try (PreparedStatement ps = conn.prepareStatement(
                        "INSERT INTO group_rounds (id, group_id, payout_member_id, round_number, due_date,"
                        + " total_expected, total_collected, status, created_at, updated_at)"
                        + " VALUES (?,?,?,?,?,?,0,?,?,?)")) {
                    ps.setString(1, roundId);
                    ps.setString(2, group.getId());
                    ps.setString(3, members.get(i).getId());
                    ps.setInt(4, roundNumber);
                    ps.setString(5, dueDate.format(DateTimeFormatter.ISO_LOCAL_DATE));
                    ps.setLong(6, totalExpected);
                    ps.setString(7, roundNumber == 1 ? GroupRoundStatus.COLLECTING.value() : GroupRoundStatus.PENDING.value());
                    ps.setString(8, now);
                    ps.setString(9, now);
                    ps.executeUpdate();
                }
            }

            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE groups_table SET liability_account_id = ?, status = ?, activated_at = ?, updated_at = ? WHERE id = ?")) {
                ps.setString(1, liabilityAccountId);
                ps.setString(2, GroupStatus.ACTIVE.value());
                ps.setString(3, activatedAt.toString());
                ps.setString(4, Instant.now().toString());
                ps.setString(5, group.getId());
                ps.executeUpdate();
            }
        }

        return findById(groupId);
    }

    private LocalDate addPeriod(LocalDate date, String frequency) {
        return "weekly".equals(frequency) ? date.plusWeeks(1) : date.plusMonths(1);
    }

    public GroupContribution recordContribution(String recordedById, String recordedByName, String memberId,
                                                 String clientReference) throws SQLException {
        String effectiveClientReference = clientReference != null ? clientReference : UUID.randomUUID().toString();

        GroupContribution existing = findContributionByClientReference(effectiveClientReference);
        if (existing != null) {
            return existing;
        }

        GroupMember member = findMemberById(memberId);
        if (member == null) {
            throw new IllegalArgumentException("Member not found.");
        }
        Group group = findById(member.getGroupId());
        GroupRound round = currentRound(group.getId());
        if (round == null) {
            throw new IllegalStateException("This group has no round currently collecting.");
        }
        if (hasContributed(round.getId(), memberId)) {
            throw new IllegalStateException("This member has already contributed to this round.");
        }

        long amount = group.getContributionAmount();
        Instant recordedAt = Instant.now();

        JournalEntry entry = ledger.post(EntryRequest.of(TransactionType.GROUP_CONTRIBUTION, List.of(
                LedgerLine.debit(chart.agentCash(recordedById, recordedByName).getId(), amount),
                LedgerLine.credit(group.getLiabilityAccountId(), amount)
        )).paymentMethod(PaymentMethod.CASH)
                .recordedBy(recordedById)
                .recordedAt(recordedAt)
                .clientReference(effectiveClientReference)
                .description("Group contribution " + group.getCode() + " round " + round.getRoundNumber()));

        String contributionId = UUID.randomUUID().toString();
        try (Connection conn = DatabaseConnection.getConnection()) {
            String now = Instant.now().toString();
            try (PreparedStatement ps = conn.prepareStatement(
                    "INSERT INTO group_contributions (id, group_round_id, group_member_id, journal_entry_id,"
                    + " recorded_by, amount, recorded_at, client_reference, created_at, updated_at)"
                    + " VALUES (?,?,?,?,?,?,?,?,?,?)")) {
                ps.setString(1, contributionId);
                ps.setString(2, round.getId());
                ps.setString(3, memberId);
                ps.setString(4, entry.getId());
                ps.setString(5, recordedById);
                ps.setLong(6, amount);
                ps.setString(7, recordedAt.toString());
                ps.setString(8, effectiveClientReference);
                ps.setString(9, now);
                ps.setString(10, now);
                ps.executeUpdate();
            }

            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE group_rounds SET total_collected = total_collected + ?, status = ?, updated_at = ? WHERE id = ?")) {
                ps.setLong(1, amount);
                ps.setString(2, GroupRoundStatus.COLLECTING.value());
                ps.setString(3, now);
                ps.setString(4, round.getId());
                ps.executeUpdate();
            }
        }

        outbox.enqueueIfHybrid("group.contribution.record", new JSONObject()
                .put("group_member_id", memberId)
                .put("client_reference", effectiveClientReference));

        return findContributionById(contributionId);
    }

    public GroupRound payoutRound(String roundId, String paidById, boolean override) throws SQLException {
        GroupRound round = findRoundById(roundId);
        if (round == null || round.getStatus() == GroupRoundStatus.COMPLETED) {
            throw new IllegalStateException("This round has already been paid out.");
        }
        if (!override && round.getTotalCollected() < round.getTotalExpected()) {
            throw new IllegalStateException("This round has not been fully collected yet. Use the override to pay out early.");
        }
        if (round.getTotalCollected() <= 0) {
            throw new IllegalStateException("Nothing has been collected for this round yet.");
        }

        Group group = findById(round.getGroupId());
        long amount = round.getTotalCollected();
        // Resolved *before* the write connection below opens — the SQLite
        // pool is single-connection, so calling this (it acquires its own
        // connection) while that one is still held open would self-deadlock.
        GroupRound nextRound = findRoundByNumber(group.getId(), round.getRoundNumber() + 1);

        JournalEntry entry = ledger.post(EntryRequest.of(TransactionType.GROUP_PAYOUT, List.of(
                LedgerLine.debit(group.getLiabilityAccountId(), amount),
                LedgerLine.credit(chart.branchCash().getId(), amount)
        )).paymentMethod(PaymentMethod.CASH)
                .recordedBy(paidById)
                .description("Group payout " + group.getCode() + " round " + round.getRoundNumber()));

        try (Connection conn = DatabaseConnection.getConnection()) {
            String now = Instant.now().toString();
            try (PreparedStatement ps = conn.prepareStatement(
                    "UPDATE group_rounds SET status = ?, payout_entry_id = ?, paid_out_at = ?, updated_at = ? WHERE id = ?")) {
                ps.setString(1, GroupRoundStatus.COMPLETED.value());
                ps.setString(2, entry.getId());
                ps.setString(3, now);
                ps.setString(4, now);
                ps.setString(5, roundId);
                ps.executeUpdate();
            }

            if (nextRound != null) {
                try (PreparedStatement ps = conn.prepareStatement(
                        "UPDATE group_rounds SET status = ?, updated_at = ? WHERE id = ?")) {
                    ps.setString(1, GroupRoundStatus.COLLECTING.value());
                    ps.setString(2, now);
                    ps.setString(3, nextRound.getId());
                    ps.executeUpdate();
                }
            } else {
                try (PreparedStatement ps = conn.prepareStatement(
                        "UPDATE groups_table SET status = ?, completed_at = ?, updated_at = ? WHERE id = ?")) {
                    ps.setString(1, GroupStatus.COMPLETED.value());
                    ps.setString(2, now);
                    ps.setString(3, now);
                    ps.setString(4, group.getId());
                    ps.executeUpdate();
                }
            }
        }

        // No sync op for payout: the backend's SyncOpType enum doesn't have a
        // group-payout case yet (unlike loan.approve/.disburse), and pushing
        // an unrecognized op_type would throw server-side and abort the
        // whole batch. Payout stays local-only for now, same as mobile
        // (which also only pays out via a direct, online-only API call).

        return findRoundById(roundId);
    }

    public GroupRound currentRound(String groupId) throws SQLException {
        for (GroupRound round : findRounds(groupId)) {
            if (round.getStatus() != GroupRoundStatus.COMPLETED) {
                return round;
            }
        }
        return null;
    }

    private boolean hasContributed(String roundId, String memberId) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT 1 FROM group_contributions WHERE group_round_id = ? AND group_member_id = ?")) {
            ps.setString(1, roundId);
            ps.setString(2, memberId);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next();
            }
        }
    }

    public List<Group> findAll() throws SQLException {
        List<Group> groups = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM groups_table ORDER BY name");
             ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                groups.add(map(rs));
            }
        }
        return groups;
    }

    public Group findById(String id) throws SQLException {
        Group group;
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM groups_table WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                if (!rs.next()) {
                    return null;
                }
                group = map(rs);
            }
        }
        group.setMembers(findMembers(id));
        group.setRounds(findRounds(id));
        return group;
    }

    public List<GroupMember> findMembers(String groupId) throws SQLException {
        List<GroupMember> members = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM group_members WHERE group_id = ? ORDER BY rotation_position")) {
            ps.setString(1, groupId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    members.add(mapMember(rs));
                }
            }
        }
        for (GroupMember member : members) {
            member.setCustomer(customerService.findById(member.getCustomerId()));
        }
        return members;
    }

    public List<GroupRound> findRounds(String groupId) throws SQLException {
        List<GroupRound> rounds = new ArrayList<>();
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM group_rounds WHERE group_id = ? ORDER BY round_number")) {
            ps.setString(1, groupId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    rounds.add(mapRound(rs));
                }
            }
        }
        for (GroupRound round : rounds) {
            round.setPayoutMember(findMemberById(round.getPayoutMemberId()));
        }
        return rounds;
    }

    public GroupMember findMemberById(String id) throws SQLException {
        GroupMember member;
        // customerService.findById() below acquires its own connection — the
        // SQLite pool is single-connection, so it must run *after* this
        // block's connection is closed, not nested inside it.
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM group_members WHERE id = ?")) {
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

    private GroupRound findRoundById(String id) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM group_rounds WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? mapRound(rs) : null;
            }
        }
    }

    private GroupRound findRoundByNumber(String groupId, int roundNumber) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM group_rounds WHERE group_id = ? AND round_number = ?")) {
            ps.setString(1, groupId);
            ps.setInt(2, roundNumber);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? mapRound(rs) : null;
            }
        }
    }

    private GroupContribution findContributionById(String id) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("SELECT * FROM group_contributions WHERE id = ?")) {
            ps.setString(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? mapContribution(rs) : null;
            }
        }
    }

    private GroupContribution findContributionByClientReference(String clientReference) throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT * FROM group_contributions WHERE client_reference = ?")) {
            ps.setString(1, clientReference);
            try (ResultSet rs = ps.executeQuery()) {
                return rs.next() ? mapContribution(rs) : null;
            }
        }
    }

    private Group map(ResultSet rs) throws SQLException {
        Group group = new Group();
        group.setId(rs.getString("id"));
        group.setLiabilityAccountId(rs.getString("liability_account_id"));
        group.setCreatedBy(rs.getString("created_by"));
        group.setName(rs.getString("name"));
        group.setCode(rs.getString("code"));
        group.setContributionAmount(rs.getLong("contribution_amount"));
        group.setFrequency(rs.getString("frequency"));
        group.setStatus(GroupStatus.fromValue(rs.getString("status")));
        String activatedAt = rs.getString("activated_at");
        group.setActivatedAt(activatedAt != null ? Instant.parse(activatedAt) : null);
        String completedAt = rs.getString("completed_at");
        group.setCompletedAt(completedAt != null ? Instant.parse(completedAt) : null);
        return group;
    }

    private GroupMember mapMember(ResultSet rs) throws SQLException {
        GroupMember member = new GroupMember();
        member.setId(rs.getString("id"));
        member.setGroupId(rs.getString("group_id"));
        member.setCustomerId(rs.getString("customer_id"));
        member.setRotationPosition(rs.getInt("rotation_position"));
        member.setStatus(rs.getString("status"));
        member.setJoinedAt(Instant.parse(rs.getString("joined_at")));
        String leftAt = rs.getString("left_at");
        member.setLeftAt(leftAt != null ? Instant.parse(leftAt) : null);
        return member;
    }

    private GroupRound mapRound(ResultSet rs) throws SQLException {
        GroupRound round = new GroupRound();
        round.setId(rs.getString("id"));
        round.setGroupId(rs.getString("group_id"));
        round.setPayoutMemberId(rs.getString("payout_member_id"));
        round.setPayoutEntryId(rs.getString("payout_entry_id"));
        round.setRoundNumber(rs.getInt("round_number"));
        round.setDueDate(LocalDate.parse(rs.getString("due_date")));
        round.setTotalExpected(rs.getLong("total_expected"));
        round.setTotalCollected(rs.getLong("total_collected"));
        round.setStatus(GroupRoundStatus.fromValue(rs.getString("status")));
        String paidOutAt = rs.getString("paid_out_at");
        round.setPaidOutAt(paidOutAt != null ? Instant.parse(paidOutAt) : null);
        return round;
    }

    private GroupContribution mapContribution(ResultSet rs) throws SQLException {
        GroupContribution contribution = new GroupContribution();
        contribution.setId(rs.getString("id"));
        contribution.setGroupRoundId(rs.getString("group_round_id"));
        contribution.setGroupMemberId(rs.getString("group_member_id"));
        contribution.setJournalEntryId(rs.getString("journal_entry_id"));
        contribution.setRecordedBy(rs.getString("recorded_by"));
        contribution.setAmount(rs.getLong("amount"));
        contribution.setRecordedAt(Instant.parse(rs.getString("recorded_at")));
        contribution.setClientReference(rs.getString("client_reference"));
        return contribution;
    }
}
