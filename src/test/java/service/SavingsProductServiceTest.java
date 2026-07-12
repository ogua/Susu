package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.UUID;
import models.SavingsProduct;
import org.json.JSONObject;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;

/**
 * Proves the client-reference-as-id sync pattern for products: a server product
 * pulled via bootstrap keeps the exact same id locally, and a repeat pull
 * updates the existing row instead of duplicating it.
 */
class SavingsProductServiceTest {

    private static String originalHome;
    private final SavingsProductService products = new SavingsProductService();

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-product-test-");
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
    void upsertInsertsThenUpdatesTheSameServerId() throws Exception {
        String serverId = UUID.randomUUID().toString();
        JSONObject payload = new JSONObject()
                .put("id", serverId)
                .put("name", "Daily Susu")
                .put("code", "MB-P001")
                .put("type", "daily_susu")
                .put("contribution_amount", 500)
                .put("cycle_length_days", 31)
                .put("commission_type", "first_contribution_per_cycle")
                .put("commission_value", 0)
                .put("is_active", true);

        products.upsertFromServer(payload);

        SavingsProduct first = products.findById(serverId);
        assertNotNull(first);
        assertEquals("Daily Susu", first.getName());
        assertEquals(500, first.getContributionAmount());

        JSONObject updated = new JSONObject(payload.toString()).put("name", "Daily Susu (Updated)").put("contribution_amount", 1000);
        products.upsertFromServer(updated);

        SavingsProduct second = products.findById(serverId);
        assertEquals(serverId, second.getId());
        assertEquals("Daily Susu (Updated)", second.getName());
        assertEquals(1000, second.getContributionAmount());
        assertEquals(1, products.findActive().stream().filter(p -> p.getId().equals(serverId)).count());
    }
}
