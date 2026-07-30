package db;

import db.provider.SQLiteProvider;
import java.io.File;
import java.nio.file.Files;
import java.nio.file.Path;
import java.sql.Connection;
import java.sql.ResultSet;
import java.sql.Statement;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.MethodOrderer;
import org.junit.jupiter.api.Order;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.TestMethodOrder;
import service.AuthService;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Exercises the SQLite provider end-to-end: config defaults, migrations,
 * and local authentication (bcrypt + lockout schema).
 * Runs against a throwaway home directory so the developer's real
 * ~/.susudesktop is never touched.
 */
@TestMethodOrder(MethodOrderer.OrderAnnotation.class)
class DbLayerTest {

    private static Path tempHome;
    private static String originalHome;

    @BeforeAll
    static void useTemporaryHome() throws Exception {
        originalHome = System.getProperty("user.home");
        tempHome = Files.createTempDirectory("susudesktop-test-");
        System.setProperty("user.home", tempHome.toString());

        AppConfig.applySQLiteDefaults();
    }

    @AfterAll
    static void restoreHome() {
        DatabaseConnection.shutdown();
        System.setProperty("user.home", originalHome);
    }

    @Test
    @Order(1)
    void sqliteProviderRunsMigrations() throws Exception {
        SQLiteProvider provider = new SQLiteProvider();
        try {
            provider.initialize();

            try (Connection conn = provider.getConnection();
                 Statement stmt = conn.createStatement();
                 ResultSet rs = stmt.executeQuery("SELECT COUNT(*) FROM schema_migrations")) {
                assertTrue(rs.next());
                assertEquals(9, rs.getInt(1), "V001-V009 should each be recorded exactly once");
            }

            // Idempotency: a second run must be a no-op, not a failure.
            new db.migration.MigrationRunner(provider).run();
        } finally {
            provider.close();
        }

        File dbFile = new File(AppConfig.getAppDir(), AppConfig.SQLITE_DB_FILE_NAME);
        assertTrue(dbFile.exists(), "SQLite database file should exist in the app dir");
    }

    @Test
    @Order(2)
    void authServiceCreatesAndAuthenticatesUsers() throws Exception {
        AuthService auth = new AuthService();

        assertFalse(auth.hasAnyUser(), "fresh database starts with no users");

        String id = auth.createUser("Test Admin", "Admin@Example.com", null, "company_admin", "secret-pass");
        assertNotNull(id);
        assertTrue(auth.hasAnyUser());

        AuthService.LoginResult ok = auth.login("admin@example.com", "secret-pass");
        assertEquals(AuthService.LoginResult.Status.SUCCESS, ok.status());
        assertEquals("company_admin", ok.user().getRole());

        AuthService.LoginResult bad = auth.login("admin@example.com", "wrong");
        assertEquals(AuthService.LoginResult.Status.INVALID, bad.status());
    }
}
