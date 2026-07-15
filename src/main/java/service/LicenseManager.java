package service;

import db.AppConfig;
import db.DatabaseConnection;
import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.security.KeyFactory;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.security.PublicKey;
import java.security.Signature;
import java.security.spec.InvalidKeySpecException;
import java.security.spec.X509EncodedKeySpec;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.time.LocalDate;
import java.time.temporal.ChronoUnit;
import java.util.Base64;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import org.json.JSONObject;

/**
 * Offline license activation, the standalone-mode mirror of Oguaschoolz's
 * {@code services.LicenseManager}. Keys are opaque strings of the form
 * {@code PAYLOAD_B64URL.SIGNATURE_B64URL} where the payload is JSON
 * {@code {"uid": "<install id>", "exp": "YYYY-MM-DD"}}, signed with
 * SHA256withRSA against a private key that never ships with the app —
 * activation only ever needs the embedded public key
 * ({@code /keys/license_public.pem}), so this works with zero connectivity.
 *
 * <p>This is the licensing <b>mechanism</b> only. What a key costs, how long
 * one is valid for, and how strictly expiry is enforced are business
 * decisions the plan deliberately left open — {@link #GRACE_DAYS} and
 * {@link #WARNING_DAYS} are reasonable placeholder defaults, not policy.</p>
 */
public class LicenseManager {

    public enum LicenseStatus { VALID, GRACE_PERIOD, EXPIRED, MISSING, TAMPERED }

    /** Days after expiry the app keeps working before a hard lock. */
    public static final int GRACE_DAYS = 7;

    /** Days before expiry the UI should start nagging about renewal. */
    public static final int WARNING_DAYS = 30;

    /** Set right before loading the license screen so its controller can show context. */
    public static volatile LicenseStatus pendingStatus;

    private static final String PUBLIC_KEY_RESOURCE = "/keys/license_public.pem";
    private static final long CLOCK_TAMPER_GRACE_SECONDS = 300;
    private static final String MAC_CONTEXT = "susu-clock-guard-v1";

    private static PublicKey cachedPublicKey;

    public static class LicenseException extends Exception {
        public LicenseException(String message) {
            super(message);
        }
    }

    private LicenseManager() {}

    /** Activates a raw key, persisting it only if it verifies for this install. */
    public static void validateAndStoreKey(String rawKey) throws LicenseException {
        String key = rawKey == null ? "" : rawKey.trim().replaceAll("\\s+", "");
        if (key.isEmpty()) {
            throw new LicenseException("Enter an activation key.");
        }

        JSONObject payload = verifyAndParsePayload(key);
        requireMatchesThisInstall(payload);
        LocalDate expiry = parseExpiry(payload);

        try {
            persist(key, expiry);
        } catch (SQLException e) {
            throw new LicenseException("Could not save the license: " + e.getMessage());
        }
    }

    /** The check — called at startup, at login, and periodically while the app is open. */
    public static LicenseStatus getLicenseStatus() {
        try {
            StoredLicense stored = loadStored();
            long nowEpoch = Instant.now().getEpochSecond();

            if (stored != null && stored.lastCheckedEpoch() != null) {
                if (!macValid(stored.lastCheckedEpoch(), stored.mac())) {
                    return LicenseStatus.TAMPERED;
                }
                if (nowEpoch < stored.lastCheckedEpoch() - CLOCK_TAMPER_GRACE_SECONDS) {
                    return LicenseStatus.TAMPERED;
                }
            }

            if (stored == null || stored.key() == null || stored.key().isBlank()) {
                return LicenseStatus.MISSING;
            }

            JSONObject payload;
            try {
                payload = verifyAndParsePayload(stored.key());
                requireMatchesThisInstall(payload);
            } catch (LicenseException e) {
                return LicenseStatus.TAMPERED;
            }

            LocalDate expiry = parseExpiry(payload);
            long daysLeft = ChronoUnit.DAYS.between(LocalDate.now(), expiry);
            LicenseStatus status = daysLeft >= 0
                    ? LicenseStatus.VALID
                    : (-daysLeft <= GRACE_DAYS ? LicenseStatus.GRACE_PERIOD : LicenseStatus.EXPIRED);

            long checkpoint = Math.max(stored.lastCheckedEpoch() != null ? stored.lastCheckedEpoch() : 0, nowEpoch);
            updateLastChecked(checkpoint);

            return status;
        } catch (LicenseException | SQLException e) {
            return LicenseStatus.TAMPERED;
        } catch (Exception e) {
            return LicenseStatus.MISSING;
        }
    }

    /** Negative once expired; {@code Long.MAX_VALUE} if there's no license to read. */
    public static long getDaysUntilExpiry() {
        try {
            StoredLicense stored = loadStored();
            if (stored == null || stored.key() == null) {
                return Long.MAX_VALUE;
            }
            JSONObject payload = verifyAndParsePayload(stored.key());
            return ChronoUnit.DAYS.between(LocalDate.now(), parseExpiry(payload));
        } catch (Exception e) {
            return Long.MAX_VALUE;
        }
    }

    public static boolean shouldShowExpiryWarning() {
        long daysLeft = getDaysUntilExpiry();
        return daysLeft >= 0 && daysLeft <= WARNING_DAYS;
    }

    public static String getStoredLicenseKey() {
        try {
            StoredLicense stored = loadStored();
            return stored != null ? stored.key() : null;
        } catch (SQLException e) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private record StoredLicense(String key, Long lastCheckedEpoch, String mac) {}

    private static StoredLicense loadStored() throws SQLException {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "SELECT license_key, last_check_at, mac FROM license WHERE id = 1")) {
            try (ResultSet rs = ps.executeQuery()) {
                if (!rs.next()) {
                    return null;
                }
                String lastCheckAt = rs.getString("last_check_at");
                Long epoch = lastCheckAt != null ? Instant.parse(lastCheckAt).getEpochSecond() : null;
                return new StoredLicense(rs.getString("license_key"), epoch, rs.getString("mac"));
            }
        }
    }

    private static void persist(String key, LocalDate expiry) throws SQLException {
        long nowEpoch = Instant.now().getEpochSecond();
        String now = Instant.now().toString();
        String mac = computeMac(nowEpoch);

        try (Connection conn = DatabaseConnection.getConnection()) {
            boolean exists;
            try (PreparedStatement check = conn.prepareStatement("SELECT COUNT(*) FROM license WHERE id = 1");
                 ResultSet rs = check.executeQuery()) {
                rs.next();
                exists = rs.getInt(1) > 0;
            }

            String sql = exists
                    ? "UPDATE license SET license_key = ?, activated_at = ?, expires_at = ?, last_check_at = ?,"
                        + " mac = ? WHERE id = 1"
                    : "INSERT INTO license (id, license_key, activated_at, expires_at, last_check_at, mac)"
                        + " VALUES (1, ?, ?, ?, ?, ?)";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setString(1, key);
                ps.setString(2, now);
                ps.setString(3, expiry.toString());
                ps.setString(4, now);
                ps.setString(5, mac);
                ps.executeUpdate();
            }
        }
    }

    private static void updateLastChecked(long epoch) {
        try (Connection conn = DatabaseConnection.getConnection();
             PreparedStatement ps = conn.prepareStatement(
                     "UPDATE license SET last_check_at = ?, mac = ? WHERE id = 1")) {
            ps.setString(1, Instant.ofEpochSecond(epoch).toString());
            ps.setString(2, computeMac(epoch));
            ps.executeUpdate();
        } catch (SQLException e) {
            // Best-effort checkpoint; a failed update just means the next
            // check re-derives from the same (unmoved) last_check_at.
        }
    }

    private static JSONObject verifyAndParsePayload(String key) throws LicenseException {
        int dot = key.indexOf('.');
        if (dot <= 0 || dot == key.length() - 1) {
            throw new LicenseException("This key is not in a recognized format.");
        }

        String payloadPart = key.substring(0, dot);
        String signaturePart = key.substring(dot + 1);

        byte[] payloadBytes;
        byte[] signatureBytes;
        try {
            payloadBytes = Base64.getUrlDecoder().decode(payloadPart);
            signatureBytes = Base64.getUrlDecoder().decode(signaturePart);
        } catch (IllegalArgumentException e) {
            throw new LicenseException("This key is not valid base64.");
        }

        try {
            Signature signature = Signature.getInstance("SHA256withRSA");
            signature.initVerify(loadPublicKey());
            signature.update(payloadBytes);
            if (!signature.verify(signatureBytes)) {
                throw new LicenseException("This key's signature does not match.");
            }
        } catch (LicenseException e) {
            throw e;
        } catch (Exception e) {
            throw new LicenseException("Could not verify this key: " + e.getMessage());
        }

        try {
            return new JSONObject(new String(payloadBytes, StandardCharsets.UTF_8));
        } catch (Exception e) {
            throw new LicenseException("This key's payload is not valid JSON.");
        }
    }

    private static void requireMatchesThisInstall(JSONObject payload) throws LicenseException {
        String uid = payload.optString("uid", null);
        if (uid == null || !uid.equals(AppConfig.getInstallId())) {
            throw new LicenseException("This key was not issued for this install.");
        }
    }

    private static LocalDate parseExpiry(JSONObject payload) throws LicenseException {
        try {
            return LocalDate.parse(payload.getString("exp"));
        } catch (Exception e) {
            throw new LicenseException("This key's expiry date is invalid.");
        }
    }

    private static boolean macValid(long epoch, String mac) {
        return mac != null && mac.equals(computeMac(epoch));
    }

    private static String computeMac(long epoch) {
        try {
            byte[] keyBytes = MessageDigest.getInstance("SHA-256")
                    .digest((macSecretMaterial()).getBytes(StandardCharsets.UTF_8));
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(keyBytes, "HmacSHA256"));
            byte[] result = mac.doFinal(String.valueOf(epoch).getBytes(StandardCharsets.UTF_8));
            return Base64.getEncoder().encodeToString(result);
        } catch (Exception e) {
            throw new IllegalStateException("Could not compute license MAC.", e);
        }
    }

    private static String macSecretMaterial() {
        return Base64.getEncoder().encodeToString(loadPublicKeyUnchecked().getEncoded()) + "|" + MAC_CONTEXT;
    }

    private static PublicKey loadPublicKeyUnchecked() {
        try {
            return loadPublicKey();
        } catch (LicenseException e) {
            throw new IllegalStateException(e.getMessage(), e);
        }
    }

    /** Test-only hook: points signature verification at a throwaway keypair instead of the shipped resource. */
    static synchronized void overridePublicKeyForTesting(PublicKey key) {
        cachedPublicKey = key;
    }

    private static synchronized PublicKey loadPublicKey() throws LicenseException {
        if (cachedPublicKey != null) {
            return cachedPublicKey;
        }

        try (InputStream in = LicenseManager.class.getResourceAsStream(PUBLIC_KEY_RESOURCE)) {
            if (in == null) {
                throw new LicenseException("The license public key is missing from this build.");
            }
            String pem = new String(in.readAllBytes(), StandardCharsets.UTF_8)
                    .replace("-----BEGIN PUBLIC KEY-----", "")
                    .replace("-----END PUBLIC KEY-----", "")
                    .replaceAll("\\s+", "");
            byte[] der = Base64.getDecoder().decode(pem);
            KeyFactory factory = KeyFactory.getInstance("RSA");
            cachedPublicKey = factory.generatePublic(new X509EncodedKeySpec(der));
            return cachedPublicKey;
        } catch (LicenseException e) {
            throw e;
        } catch (IOException | NoSuchAlgorithmException | InvalidKeySpecException e) {
            throw new LicenseException("Could not load the license public key: " + e.getMessage());
        }
    }
}
