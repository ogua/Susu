package tools;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.KeyFactory;
import java.security.PrivateKey;
import java.security.Signature;
import java.security.spec.PKCS8EncodedKeySpec;
import java.time.LocalDate;
import java.util.Base64;
import org.json.JSONObject;

/**
 * Offline command-line tool for minting SusuApp Desktop activation keys —
 * the counterpart to {@link service.LicenseManager}. Run with the matching
 * RSA private key (never bundled in the shipped app) to sign a key for one
 * install id and expiry date:
 *
 * <pre>
 *   java -cp target/classes tools.LicenseKeyGenerator \
 *       path/to/private_key.pem &lt;install-id&gt; &lt;YYYY-MM-DD&gt;
 * </pre>
 *
 * <p><b>This is a mechanism, not a policy.</b> How long a key is valid for,
 * what it costs, and who is authorized to run this tool are business
 * decisions outside this codebase's scope. No private key ships with this
 * repository — generate your own RSA keypair (e.g. {@code openssl genpkey
 * -algorithm RSA -pkeyopt rsa_keygen_bits:2048}), publish the public half at
 * {@code src/main/resources/keys/license_public.pem}, and keep the private
 * half somewhere outside version control (a secrets manager, not a file in
 * this repo) before issuing any real key.</p>
 */
public final class LicenseKeyGenerator {

    private LicenseKeyGenerator() {}

    public static void main(String[] args) throws Exception {
        if (args.length != 3) {
            System.err.println("Usage: LicenseKeyGenerator <private-key.pem> <install-id> <expiry YYYY-MM-DD>");
            System.exit(1);
            return;
        }

        Path privateKeyPath = Path.of(args[0]);
        String installId = args[1];
        LocalDate expiry = LocalDate.parse(args[2]);

        String key = generate(privateKeyPath, installId, expiry);
        System.out.println(key);
    }

    public static String generate(Path privateKeyPath, String installId, LocalDate expiry) throws Exception {
        PrivateKey privateKey = loadPrivateKey(privateKeyPath);

        JSONObject payload = new JSONObject()
                .put("uid", installId)
                .put("exp", expiry.toString());
        byte[] payloadBytes = payload.toString().getBytes(StandardCharsets.UTF_8);

        Signature signature = Signature.getInstance("SHA256withRSA");
        signature.initSign(privateKey);
        signature.update(payloadBytes);
        byte[] signatureBytes = signature.sign();

        return Base64.getUrlEncoder().withoutPadding().encodeToString(payloadBytes)
                + "."
                + Base64.getUrlEncoder().withoutPadding().encodeToString(signatureBytes);
    }

    private static PrivateKey loadPrivateKey(Path path) throws Exception {
        String pem = Files.readString(path, StandardCharsets.UTF_8)
                .replace("-----BEGIN PRIVATE KEY-----", "")
                .replace("-----END PRIVATE KEY-----", "")
                .replaceAll("\\s+", "");
        byte[] der = Base64.getDecoder().decode(pem);
        KeyFactory factory = KeyFactory.getInstance("RSA");
        return factory.generatePrivate(new PKCS8EncodedKeySpec(der));
    }
}
