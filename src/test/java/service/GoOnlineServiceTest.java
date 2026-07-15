package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Instant;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.stream.Collectors;
import models.Customer;
import models.Group;
import models.Loan;
import models.LoanProduct;
import models.SavingsAccount;
import models.SavingsProduct;
import org.json.JSONObject;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Verifies the outbox backfill queues one op per historical event, correctly
 * skips deriving 'commission' entries as separate collections, and resolves
 * loan_id for repayments via the receivable-account join.
 */
class GoOnlineServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final SavingsAccountService accounts = new SavingsAccountService();
    private final SavingsProductService savingsProducts = new SavingsProductService();
    private final LoanProductService loanProducts = new LoanProductService();
    private final CollectionService collections = new CollectionService();
    private final LoanService loans = new LoanService();
    private final GroupService groups = new GroupService();
    private final OutboxService outbox = new OutboxService();
    private final GoOnlineService goOnlineService = new GoOnlineService();
    private static final String AGENT_ID = "agent-goonline";
    private static final String MANAGER_ID = "manager-goonline";

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-goonline-test-");
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

    @Test
    void backfillsEveryHistoricalEventTypeAsAnIdempotentReplayableOp() throws Exception {
        // Susu account with two real collections (each carries a day-1
        // commission entry that must NOT also be queued as a collection).
        Customer customer = newCustomer("Abena", "GoOnline");
        SavingsProduct savingsProduct = savingsProducts.getOrCreateDefault();
        SavingsAccount account = accounts.open(customer.getId(), savingsProduct.getId(), AGENT_ID, null);
        collections.record(AGENT_ID, "Agent", account.getId(), account.getContributionAmount(), null, null);
        collections.record(AGENT_ID, "Agent", account.getId(), account.getContributionAmount(), null, null);

        // A full loan lifecycle: applied, approved, disbursed, partially repaid.
        LoanProduct loanProduct = loanProducts.getOrCreateDefault();
        Loan applied = loans.apply(AGENT_ID, customer.getId(), loanProduct.getId(), 300_00, null, null, null, null, null);
        loans.approve(applied.getId(), MANAGER_ID);
        Loan disbursed = loans.disburse(applied.getId(), MANAGER_ID);
        loans.recordRepayment(disbursed.getId(), 50_00, MANAGER_ID, null, Instant.now());

        // A rejected loan for a second customer.
        Customer rejectedCustomer = newCustomer("Kojo", "Rejected");
        Loan rejectedLoan = loans.apply(AGENT_ID, rejectedCustomer.getId(), loanProduct.getId(), 100_00,
                null, null, null, null, null);
        loans.reject(rejectedLoan.getId(), MANAGER_ID, "Insufficient history");

        // A susu group with one contribution.
        String groupId = UUID.randomUUID().toString();
        try (var conn = DatabaseConnection.getConnection();
             var ps = conn.prepareStatement(
                     "INSERT INTO groups_table (id, name, code, contribution_amount, frequency, status,"
                     + " created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)")) {
            String now = Instant.now().toString();
            ps.setString(1, groupId);
            ps.setString(2, "Go Online Group");
            ps.setString(3, "GRP-" + groupId.substring(0, 6));
            ps.setLong(4, 100_00);
            ps.setString(5, "monthly");
            ps.setString(6, "draft");
            ps.setString(7, now);
            ps.setString(8, now);
            ps.executeUpdate();
        }
        Customer groupMember = newCustomer("Yaw", "Member");
        groups.addMember(groupId, groupMember.getId(), 1);
        Customer secondGroupMember = newCustomer("Efua", "Member");
        groups.addMember(groupId, secondGroupMember.getId(), 2);
        Group group = groups.activate(groupId);
        var members = groups.findMembers(group.getId());
        groups.recordContribution(AGENT_ID, "Agent", members.get(0).getId(), null);

        List<GoOnlineService.BackfillResult> results = goOnlineService.backfillOutbox();
        Map<String, Integer> queuedByLabel = results.stream()
                .collect(Collectors.toMap(GoOnlineService.BackfillResult::label, GoOnlineService.BackfillResult::queued));

        assertEquals(4, queuedByLabel.get("customer.register"));
        assertEquals(1, queuedByLabel.get("account.open"));
        assertEquals(2, queuedByLabel.get("collection.record"), "only 'collection' entries, not the commission entries too");
        // apply x2 (approved loan + rejected loan) + approve x1 + disburse x1 + reject x1 = 5.
        assertEquals(5, queuedByLabel.get("loan.apply/approve/reject/disburse"));
        assertEquals(1, queuedByLabel.get("loan.repayment.record"));
        assertEquals(1, queuedByLabel.get("group.contribution.record"));

        List<OutboxService.OutboxItem> pending = outbox.pending(200);
        assertEquals(14, pending.size());

        OutboxService.OutboxItem collectionOp = pending.stream()
                .filter(item -> item.opType().equals("collection.record")).findFirst().orElseThrow();
        JSONObject payload = new JSONObject(collectionOp.payload());
        assertEquals(account.getId(), payload.getString("savings_account_id"));
        assertEquals(account.getContributionAmount(), payload.getLong("amount"));

        OutboxService.OutboxItem repaymentOp = pending.stream()
                .filter(item -> item.opType().equals("loan.repayment.record")).findFirst().orElseThrow();
        JSONObject repaymentPayload = new JSONObject(repaymentOp.payload());
        assertEquals(disbursed.getId(), repaymentPayload.getString("loan_id"));
        assertEquals(50_00, repaymentPayload.getLong("amount"));

        // Re-running is safe: it queues fresh ops (the outbox itself has no
        // dedup), but every payload's client_reference is unchanged, so the
        // server-side idempotency check (client_reference/op_id) is what
        // actually prevents double-posting once uploaded.
        List<GoOnlineService.BackfillResult> secondRun = goOnlineService.backfillOutbox();
        assertTrue(secondRun.stream().anyMatch(r -> r.label().equals("customer.register") && r.queued() == 4));
    }
}
