package service;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.SQLiteProvider;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.KeyPair;
import java.security.KeyPairGenerator;
import java.security.PrivateKey;
import java.security.Signature;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.time.Instant;
import java.time.LocalDate;
import java.util.Base64;
import org.json.JSONObject;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Signs test keys with a throwaway keypair generated per test run (via
 * {@link LicenseManager#overridePublicKeyForTesting}) — never the real
 * shipped keypair, so this suite carries no secret material of its own.
 */
class LicenseManagerTest {

    private static String originalHome;
    private static PrivateKey testPrivateKey;

    @BeforeAll
    static void setUp() throws Exception {
        originalHome = System.getProperty("user.home");
        Path tempHome = Files.createTempDirectory("susudesktop-license-test-");
        System.setProperty("user.home", tempHome.toString());

        AppConfig.applySQLiteDefaults();
        new SQLiteProvider().initialize();

        KeyPairGenerator generator = KeyPairGenerator.getInstance("RSA");
        generator.initialize(2048);
        KeyPair pair = generator.generateKeyPair();
        testPrivateKey = pair.getPrivate();
        LicenseManager.overridePublicKeyForTesting(pair.getPublic());
    }

    @AfterAll
    static void tearDown() {
        DatabaseConnection.shutdown();
        System.setProperty("user.home", originalHome);
    }

    @BeforeEach
    void clearLicenseRow() throws Exception {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("DELETE FROM license")) {
            ps.executeUpdate();
        }
    }

    private String signKey(String installId, LocalDate expiry) throws Exception {
        JSONObject payload = new JSONObject().put("uid", installId).put("exp", expiry.toString());
        byte[] payloadBytes = payload.toString().getBytes(StandardCharsets.UTF_8);

        Signature signature = Signature.getInstance("SHA256withRSA");
        signature.initSign(testPrivateKey);
        signature.update(payloadBytes);

        return Base64.getUrlEncoder().withoutPadding().encodeToString(payloadBytes)
                + "." + Base64.getUrlEncoder().withoutPadding().encodeToString(signature.sign());
    }

    @Test
    void hasNoLicenseUntilOneIsActivated() {
        assertEquals(LicenseManager.LicenseStatus.MISSING, LicenseManager.getLicenseStatus());
    }

    @Test
    void activatesAValidKeyForThisInstall() throws Exception {
        String key = signKey(AppConfig.getInstallId(), LocalDate.now().plusYears(1));

        LicenseManager.validateAndStoreKey(key);

        assertEquals(LicenseManager.LicenseStatus.VALID, LicenseManager.getLicenseStatus());
        assertEquals(key, LicenseManager.getStoredLicenseKey());
    }

    @Test
    void rejectsAKeyIssuedForADifferentInstall() throws Exception {
        String key = signKey("some-other-install-id", LocalDate.now().plusYears(1));

        assertThrows(LicenseManager.LicenseException.class, () -> LicenseManager.validateAndStoreKey(key));
        assertEquals(LicenseManager.LicenseStatus.MISSING, LicenseManager.getLicenseStatus());
    }

    @Test
    void rejectsATamperedPayload() throws Exception {
        String key = signKey(AppConfig.getInstallId(), LocalDate.now().plusYears(1));
        String[] parts = key.split("\\.", 2);
        JSONObject forged = new JSONObject(new String(Base64.getUrlDecoder().decode(parts[0]), StandardCharsets.UTF_8))
                .put("exp", LocalDate.now().plusYears(50).toString());
        String forgedKey = Base64.getUrlEncoder().withoutPadding()
                .encodeToString(forged.toString().getBytes(StandardCharsets.UTF_8)) + "." + parts[1];

        assertThrows(LicenseManager.LicenseException.class, () -> LicenseManager.validateAndStoreKey(forgedKey));
    }

    @Test
    void entersGracePeriodJustAfterExpiryThenExpiresAfterTheGraceWindow() throws Exception {
        String justExpired = signKey(AppConfig.getInstallId(), LocalDate.now().minusDays(1));
        LicenseManager.validateAndStoreKey(justExpired);
        assertEquals(LicenseManager.LicenseStatus.GRACE_PERIOD, LicenseManager.getLicenseStatus());

        String longExpired = signKey(AppConfig.getInstallId(), LocalDate.now().minusDays(LicenseManager.GRACE_DAYS + 1));
        LicenseManager.validateAndStoreKey(longExpired);
        assertEquals(LicenseManager.LicenseStatus.EXPIRED, LicenseManager.getLicenseStatus());
    }

    @Test
    void showsAnExpiryWarningInsideTheWarningWindowOnly() throws Exception {
        String soonExpiring = signKey(AppConfig.getInstallId(), LocalDate.now().plusDays(10));
        LicenseManager.validateAndStoreKey(soonExpiring);
        assertTrue(LicenseManager.shouldShowExpiryWarning());

        String farFromExpiring = signKey(AppConfig.getInstallId(), LocalDate.now().plusYears(2));
        LicenseManager.validateAndStoreKey(farFromExpiring);
        assertTrue(!LicenseManager.shouldShowExpiryWarning());
    }

    @Test
    void detectsTheCheckpointRowBeingEditedDirectly() throws Exception {
        String key = signKey(AppConfig.getInstallId(), LocalDate.now().plusYears(1));
        LicenseManager.validateAndStoreKey(key);
        assertEquals(LicenseManager.LicenseStatus.VALID, LicenseManager.getLicenseStatus());

        // Editing last_check_at directly (e.g. to roll a clock-rollback check
        // back) invalidates its mac, since the mac is only ever recomputed by
        // LicenseManager itself alongside the value it covers.
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement("UPDATE license SET last_check_at = ? WHERE id = 1")) {
            ps.setString(1, Instant.now().plusSeconds(3600).toString());
            ps.executeUpdate();
        }

        assertEquals(LicenseManager.LicenseStatus.TAMPERED, LicenseManager.getLicenseStatus());
    }
}
