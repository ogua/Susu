package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import org.json.JSONObject;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

/** Mirrors the mobile app's src/sync/outbox.ts contract: enqueue, pending, synced/rejected transitions. */
class OutboxServiceTest {

    private static String originalHome;
    private final OutboxService outbox = new OutboxService();

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-outbox-test-");
        System.setProperty("user.home", tempHome.toString());

        AppConfig.applySQLiteDefaults();
        new SQLiteProvider().initialize();
    }

    @AfterAll
    static void restoreHome() {
        DatabaseConnection.shutdown();
        System.setProperty("user.home", originalHome);
    }

    @Test
    void doesNotEnqueueInStandaloneMode() throws Exception {
        AppConfig.set("sync.enabled", "false");

        outbox.enqueueIfHybrid("customer.register", new JSONObject().put("first_name", "Ama"));

        assertEquals(0, outbox.pendingCount());
    }

    @Test
    void enqueuesInHybridModeAndDrainsThroughSyncedAndRejected() throws Exception {
        AppConfig.set("sync.enabled", "true");
        int before = outbox.pendingCount();

        outbox.enqueueIfHybrid("collection.record", new JSONObject()
                .put("savings_account_id", "acct-1")
                .put("amount", 500));

        assertEquals(before + 1, outbox.pendingCount());

        List<OutboxService.OutboxItem> pending = outbox.pending(10);
        OutboxService.OutboxItem item = pending.get(pending.size() - 1);
        assertEquals("collection.record", item.opType());
        assertTrue(item.payload().contains("acct-1"));

        outbox.markSynced(item.opId());
        assertEquals(before, outbox.pendingCount());

        outbox.enqueueIfHybrid("summary.submit", new JSONObject().put("declared_cash", 1000));
        List<OutboxService.OutboxItem> second = outbox.pending(10);
        String rejectedOpId = second.get(second.size() - 1).opId();
        outbox.markRejected(rejectedOpId, "Rejected by server.");

        assertEquals(before, outbox.pendingCount());

        AppConfig.set("sync.enabled", "false");
    }
}
