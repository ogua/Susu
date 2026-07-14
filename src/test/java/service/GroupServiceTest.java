package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import enums.GroupRoundStatus;
import enums.GroupStatus;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Instant;
import java.util.List;
import java.util.UUID;
import models.Customer;
import models.Group;
import models.GroupMember;
import models.GroupRound;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * End-to-end run of the standalone-mode ROSCA engine: add members, activate
 * (generating every round upfront), collect contributions, pay a round out,
 * and confirm the rotation auto-advances. Mirrors the shape of
 * tests/Feature/Groups/GroupLifecycleTest.php.
 */
class GroupServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final GroupService groups = new GroupService();
    private final LedgerService ledger = new LedgerService();
    private final ChartOfAccounts chart = new ChartOfAccounts();
    private static final String AGENT_ID = "agent-1";
    private static final String AGENT_NAME = "Test Agent";
    private static final String MANAGER_ID = "manager-1";

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-group-test-");
        System.setProperty("user.home", tempHome.toString());

        AppConfig.applySQLiteDefaults();
        new SQLiteProvider().initialize();
    }

    @AfterAll
    static void restoreHome() {
        DatabaseConnection.shutdown();
        System.setProperty("user.home", originalHome);
    }

    private Customer newCustomer(String first, String last) throws Exception {
        Customer customer = new Customer();
        customer.setFirstName(first);
        customer.setLastName(last);
        customer.setPhone("+233" + UUID.randomUUID().toString().substring(0, 8));
        return customers.register(customer, "setup", null);
    }

    private Group newGroup(long contributionAmount) throws Exception {
        String id = UUID.randomUUID().toString();
        // The insert connection must be closed *before* calling groups.findById()
        // below — the SQLite pool is single-connection, so holding this one
        // open while findById() tries to acquire another would self-deadlock.
        try (var conn = DatabaseConnection.getConnection();
             var ps = conn.prepareStatement(
                     "INSERT INTO groups_table (id, name, code, contribution_amount, frequency, status,"
                     + " created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)")) {
            String now = Instant.now().toString();
            ps.setString(1, id);
            ps.setString(2, "Test Group " + id.substring(0, 6));
            ps.setString(3, "GRP-" + id.substring(0, 6));
            ps.setLong(4, contributionAmount);
            ps.setString(5, "monthly");
            ps.setString(6, "draft");
            ps.setString(7, now);
            ps.setString(8, now);
            ps.executeUpdate();
        }
        return groups.findById(id);
    }

    @Test
    void activatesAGroupAndGeneratesOneRoundPerMemberInRotationOrder() throws Exception {
        Group group = newGroup(1000);
        for (int position = 1; position <= 3; position++) {
            Customer customer = newCustomer("Member", String.valueOf(position));
            groups.addMember(group.getId(), customer.getId(), position);
        }

        Group activated = groups.activate(group.getId());
        assertEquals(GroupStatus.ACTIVE, activated.getStatus());
        assertNotNull(activated.getActivatedAt());

        List<GroupRound> rounds = groups.findRounds(group.getId());
        assertEquals(3, rounds.size());
        assertEquals(GroupRoundStatus.COLLECTING, rounds.get(0).getStatus());
        assertEquals(GroupRoundStatus.PENDING, rounds.get(1).getStatus());
        assertEquals(GroupRoundStatus.PENDING, rounds.get(2).getStatus());

        for (int i = 0; i < rounds.size(); i++) {
            assertEquals(i + 1, rounds.get(i).getPayoutMember().getRotationPosition());
            assertEquals(3000, rounds.get(i).getTotalExpected());
        }
    }

    @Test
    void refusesToActivateAGroupWithFewerThanTwoMembers() throws Exception {
        Group group = newGroup(1000);
        groups.addMember(group.getId(), newCustomer("Solo", "Member").getId(), 1);

        assertThrows(IllegalStateException.class, () -> groups.activate(group.getId()));
    }

    @Test
    void refusesToActivateAGroupWithNonContiguousRotationPositions() throws Exception {
        Group group = newGroup(1000);
        groups.addMember(group.getId(), newCustomer("A", "One").getId(), 1);
        groups.addMember(group.getId(), newCustomer("B", "Two").getId(), 5);

        assertThrows(IllegalStateException.class, () -> groups.activate(group.getId()));
    }

    @Test
    void refusesToAddAMemberOnceTheGroupIsActive() throws Exception {
        Group group = newGroup(1000);
        groups.addMember(group.getId(), newCustomer("A", "One").getId(), 1);
        groups.addMember(group.getId(), newCustomer("B", "Two").getId(), 2);
        groups.activate(group.getId());

        assertThrows(IllegalStateException.class,
                () -> groups.addMember(group.getId(), newCustomer("C", "Three").getId(), 3));
    }

    @Test
    void collectsContributionsFromEveryMemberAndPaysOutTheRoundToTheRotationMember() throws Exception {
        Group group = newGroup(1000);
        List<GroupMember> members = new java.util.ArrayList<>();
        for (int position = 1; position <= 3; position++) {
            Customer customer = newCustomer("Payout", String.valueOf(position));
            members.add(groups.addMember(group.getId(), customer.getId(), position));
        }
        groups.activate(group.getId());

        for (GroupMember member : members) {
            groups.recordContribution(AGENT_ID, AGENT_NAME, member.getId(), null);
        }

        List<GroupRound> rounds = groups.findRounds(group.getId());
        assertEquals(3000, rounds.get(0).getTotalCollected());

        GroupRound paid = groups.payoutRound(rounds.get(0).getId(), MANAGER_ID, false);
        assertEquals(GroupRoundStatus.COMPLETED, paid.getStatus());
        assertNotNull(paid.getPayoutEntryId());

        Group fresh = groups.findById(group.getId());
        var groupLiability = chart.groupLiability(fresh.getId(), fresh.getCode());
        assertEquals(0, groupLiability.getBalance()); // fully paid out, pot empty again

        List<GroupRound> refreshedRounds = groups.findRounds(group.getId());
        assertEquals(GroupRoundStatus.COLLECTING, refreshedRounds.get(1).getStatus());
    }

    @Test
    void rejectsADuplicateContributionFromTheSameMemberForTheSameRound() throws Exception {
        Group group = newGroup(1000);
        GroupMember member = groups.addMember(group.getId(), newCustomer("Dup", "One").getId(), 1);
        groups.addMember(group.getId(), newCustomer("Dup", "Two").getId(), 2);
        groups.activate(group.getId());

        groups.recordContribution(AGENT_ID, AGENT_NAME, member.getId(), null);

        assertThrows(IllegalStateException.class,
                () -> groups.recordContribution(AGENT_ID, AGENT_NAME, member.getId(), null));
    }

    @Test
    void isIdempotentWhenTheSameClientReferenceIsReplayed() throws Exception {
        Group group = newGroup(1000);
        GroupMember member = groups.addMember(group.getId(), newCustomer("Idem", "One").getId(), 1);
        groups.addMember(group.getId(), newCustomer("Idem", "Two").getId(), 2);
        groups.activate(group.getId());

        String ref = UUID.randomUUID().toString();
        var first = groups.recordContribution(AGENT_ID, AGENT_NAME, member.getId(), ref);
        var second = groups.recordContribution(AGENT_ID, AGENT_NAME, member.getId(), ref);

        assertEquals(first.getId(), second.getId());
        List<GroupRound> rounds = groups.findRounds(group.getId());
        assertEquals(1000, rounds.get(0).getTotalCollected()); // only applied once
    }

    @Test
    void refusesToPayOutARoundThatIsNotFullyCollectedWithoutAnOverride() throws Exception {
        Group group = newGroup(1000);
        GroupMember member = groups.addMember(group.getId(), newCustomer("Partial", "One").getId(), 1);
        groups.addMember(group.getId(), newCustomer("Partial", "Two").getId(), 2);
        groups.activate(group.getId());

        groups.recordContribution(AGENT_ID, AGENT_NAME, member.getId(), null);

        List<GroupRound> rounds = groups.findRounds(group.getId());
        assertThrows(IllegalStateException.class,
                () -> groups.payoutRound(rounds.get(0).getId(), MANAGER_ID, false));
    }

    @Test
    void allowsAnEarlyPayoutWithTheOverrideFlag() throws Exception {
        Group group = newGroup(1000);
        GroupMember member = groups.addMember(group.getId(), newCustomer("Early", "One").getId(), 1);
        groups.addMember(group.getId(), newCustomer("Early", "Two").getId(), 2);
        groups.activate(group.getId());

        groups.recordContribution(AGENT_ID, AGENT_NAME, member.getId(), null);

        List<GroupRound> rounds = groups.findRounds(group.getId());
        GroupRound paid = groups.payoutRound(rounds.get(0).getId(), MANAGER_ID, true);

        assertEquals(GroupRoundStatus.COMPLETED, paid.getStatus());
        assertEquals(1000, paid.getTotalCollected()); // only what was actually collected
    }

    @Test
    void completesTheGroupOnceEveryRoundHasBeenPaidOut() throws Exception {
        Group group = newGroup(1000);
        List<GroupMember> members = new java.util.ArrayList<>();
        for (int position = 1; position <= 3; position++) {
            members.add(groups.addMember(group.getId(), newCustomer("Full", String.valueOf(position)).getId(), position));
        }
        groups.activate(group.getId());

        for (int round = 0; round < 3; round++) {
            for (GroupMember member : members) {
                groups.recordContribution(AGENT_ID, AGENT_NAME, member.getId(), null);
            }
            List<GroupRound> rounds = groups.findRounds(group.getId());
            GroupRound current = rounds.stream()
                    .filter(r -> r.getStatus() != GroupRoundStatus.COMPLETED)
                    .findFirst().orElseThrow();
            groups.payoutRound(current.getId(), MANAGER_ID, false);
        }

        Group fresh = groups.findById(group.getId());
        assertEquals(GroupStatus.COMPLETED, fresh.getStatus());
        assertNotNull(fresh.getCompletedAt());

        var groupLiability = chart.groupLiability(fresh.getId(), fresh.getCode());
        assertEquals(0, ledger.recomputeBalance(groupLiability));
    }
}
