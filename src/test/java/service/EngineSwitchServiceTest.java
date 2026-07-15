package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import java.nio.file.Files;
import java.nio.file.Path;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.util.List;
import java.util.UUID;
import models.Customer;
import models.SavingsAccount;
import models.SavingsProduct;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Uses two SQLite files (source = the active DatabaseConnection provider,
 * target = a second throwaway file) instead of a real MySQL server, so the
 * generic table-by-table copy mechanism can be verified without external
 * infrastructure — the copy logic itself is engine-agnostic JDBC.
 */
class EngineSwitchServiceTest {

    private static String originalHome;
    private final CustomerService customers = new CustomerService();
    private final SavingsAccountService accounts = new SavingsAccountService();
    private final SavingsProductService products = new SavingsProductService();
    private final CollectionService collections = new CollectionService();
    private final EngineSwitchService engineSwitchService = new EngineSwitchService();

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-engineswitch-test-");
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
    void copiesEveryPopulatedTableAndMatchesRowCounts() throws Exception {
        Customer customer = new Customer();
        customer.setFirstName("Ama");
        customer.setLastName("Engine");
        customer.setPhone("+233" + UUID.randomUUID().toString().substring(0, 8));
        customer = customers.register(customer, "setup", null);

        SavingsProduct product = products.getOrCreateDefault();
        SavingsAccount account = accounts.open(customer.getId(), product.getId(), "agent-1", null);
        collections.record("agent-1", "Agent One", account.getId(), account.getContributionAmount(), null, null);

        Path targetFile = Files.createTempFile("susudesktop-engineswitch-target-", ".db");
        Files.deleteIfExists(targetFile); // SQLiteProvider creates it fresh
        SQLiteProvider target = new SQLiteProvider("jdbc:sqlite:" + targetFile.toAbsolutePath());

        try {
            assertTrue(!target.hasExistingData(), "a brand-new target file must report no existing data");
            target.initialize();

            List<EngineSwitchService.TableResult> results = engineSwitchService.migrate(target);

            assertTrue(results.stream().allMatch(EngineSwitchService.TableResult::matches),
                    "every table's source and target row counts must match");

            EngineSwitchService.TableResult customersResult = results.stream()
                    .filter(r -> r.table().equals("customers")).findFirst().orElseThrow();
            assertEquals(1, customersResult.sourceRows());

            try (Connection targetConn = target.getConnection();
                 PreparedStatement ps = targetConn.prepareStatement(
                         "SELECT first_name FROM customers WHERE id = ?")) {
                ps.setString(1, customer.getId());
                try (ResultSet rs = ps.executeQuery()) {
                    assertTrue(rs.next(), "the customer row must exist in the target database");
                    assertEquals("Ama", rs.getString("first_name"));
                }
            }
        } finally {
            target.close();
            Files.deleteIfExists(targetFile);
        }
    }
}
