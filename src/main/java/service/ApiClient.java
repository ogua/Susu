package service;

import db.AppConfig;
import java.io.IOException;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.time.Duration;
import java.util.Optional;
import org.json.JSONArray;
import org.json.JSONObject;

/**
 * Thin HTTP client to the SusuApp Laravel backend, used only in hybrid mode
 * (AppConfig.isSyncEnabled()). Mirrors the mobile app's axios client contract
 * exactly: POST /api/v1/auth/login and POST /api/v1/sync/batch (AD-3) — same
 * request/response shapes, so the server can't tell which client sent them.
 */
public class ApiClient {

    public static class ApiException extends Exception {
        public ApiException(String message) {
            super(message);
        }

        public ApiException(String message, Throwable cause) {
            super(message, cause);
        }
    }

    /**
     * Sent on every request so the server can show which desktop build each
     * signed-in computer runs (super admin Devices page). The jar manifest
     * carries the version in packaged builds; a dev run reports "dev".
     */
    private static final String APP_VERSION = Optional.ofNullable(ApiClient.class.getPackage().getImplementationVersion()).orElse("dev");

    private final HttpClient http = HttpClient.newBuilder()
            .connectTimeout(Duration.ofSeconds(10))
            .build();

    /** Authenticates against the server and caches the returned Sanctum token. */
    public JSONObject login(String login, String password, String deviceName) throws ApiException {
        JSONObject body = new JSONObject()
                .put("login", login)
                .put("password", password)
                .put("device_name", deviceName);

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl() + "/api/v1/auth/login"))
                .timeout(Duration.ofSeconds(20))
                .header("Content-Type", "application/json")
                .header("Accept", "application/json")
                .header("X-Client-Platform", "desktop")
                .header("X-App-Version", APP_VERSION)
                .POST(HttpRequest.BodyPublishers.ofString(body.toString()))
                .build();

        JSONObject json = send(request);
        AppConfig.setApiToken(json.getString("token"));
        return json;
    }

    /**
     * Replaces the signed-in user's password. The server refuses every other
     * call (403, code "password_change_required") while the account is still
     * on a temporary password sent at onboarding or after an admin reset.
     */
    public JSONObject changePassword(String currentPassword, String newPassword) throws ApiException {
        String token = requireToken();

        JSONObject body = new JSONObject()
                .put("current_password", currentPassword)
                .put("password", newPassword)
                .put("password_confirmation", newPassword);

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl() + "/api/v1/auth/password"))
                .timeout(Duration.ofSeconds(20))
                .header("Content-Type", "application/json")
                .header("Accept", "application/json")
                .header("X-Client-Platform", "desktop")
                .header("X-App-Version", APP_VERSION)
                .header("Authorization", "Bearer " + token)
                .PUT(HttpRequest.BodyPublishers.ofString(body.toString()))
                .build();

        return send(request);
    }

    /** Platform announcements from the SusuApp team running now for the signed-in user. Requires a cached token. */
    public JSONObject listAnnouncements() throws ApiException {
        String token = requireToken();

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl() + "/api/v1/announcements"))
                .timeout(Duration.ofSeconds(20))
                .header("Accept", "application/json")
                .header("X-Client-Platform", "desktop")
                .header("X-App-Version", APP_VERSION)
                .header("Authorization", "Bearer " + token)
                .GET()
                .build();

        return send(request);
    }

    /** Full snapshot of the caller's working set (accounts/customers/products). Requires a cached token. */
    public JSONObject bootstrap() throws ApiException {
        String token = requireToken();

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl() + "/api/v1/sync/bootstrap"))
                .timeout(Duration.ofSeconds(20))
                .header("Accept", "application/json")
                .header("X-Client-Platform", "desktop")
                .header("X-App-Version", APP_VERSION)
                .header("Authorization", "Bearer " + token)
                .GET()
                .build();

        return send(request);
    }

    /** Pushes queued outbox ops to POST /sync/batch. Requires a cached token (see {@link #login}). */
    public JSONObject pushSyncBatch(JSONArray ops) throws ApiException {
        String token = requireToken();

        JSONObject body = new JSONObject().put("ops", ops);

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl() + "/api/v1/sync/batch"))
                .timeout(Duration.ofSeconds(30))
                .header("Content-Type", "application/json")
                .header("Accept", "application/json")
                .header("X-Client-Platform", "desktop")
                .header("X-App-Version", APP_VERSION)
                .header("Authorization", "Bearer " + token)
                .header("X-Client-Origin", "desktop")
                .POST(HttpRequest.BodyPublishers.ofString(body.toString()))
                .build();

        return send(request);
    }

    /** Read-only list for the desktop's hybrid-mode payments view. Requires a cached token. */
    public JSONObject listPaymentIntents() throws ApiException {
        String token = requireToken();

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl() + "/api/v1/payments"))
                .timeout(Duration.ofSeconds(20))
                .header("Accept", "application/json")
                .header("X-Client-Platform", "desktop")
                .header("X-App-Version", APP_VERSION)
                .header("Authorization", "Bearer " + token)
                .GET()
                .build();

        return send(request);
    }

    private String requireToken() throws ApiException {
        String token = AppConfig.getApiToken();
        if (token == null || token.isBlank()) {
            throw new ApiException("Not signed in to the server. Log in online at least once to enable sync.");
        }
        return token;
    }

    private String baseUrl() {
        String url = AppConfig.getApiBaseUrl();
        return url.endsWith("/") ? url.substring(0, url.length() - 1) : url;
    }

    private JSONObject send(HttpRequest request) throws ApiException {
        HttpResponse<String> response;
        try {
            response = http.send(request, HttpResponse.BodyHandlers.ofString());
        } catch (IOException e) {
            throw new ApiException("Could not reach the server: " + e.getMessage(), e);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            throw new ApiException("Request interrupted.", e);
        }

        JSONObject json;
        try {
            json = new JSONObject(response.body());
        } catch (Exception e) {
            throw new ApiException("Server returned an unexpected response (HTTP " + response.statusCode() + ").");
        }

        if (response.statusCode() >= 400) {
            throw new ApiException(extractError(json, response.statusCode()));
        }

        return json;
    }

    private String extractError(JSONObject json, int statusCode) {
        if (json.has("message")) {
            return json.getString("message");
        }
        JSONObject errors = json.optJSONObject("errors");
        if (errors != null && !errors.keySet().isEmpty()) {
            String firstKey = errors.keySet().iterator().next();
            JSONArray messages = errors.optJSONArray(firstKey);
            if (messages != null && !messages.isEmpty()) {
                return messages.getString(0);
            }
        }
        return "Request failed (HTTP " + statusCode + ").";
    }
}
