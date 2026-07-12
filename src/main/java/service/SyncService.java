package service;

import java.sql.SQLException;
import java.util.List;
import org.json.JSONArray;
import org.json.JSONObject;

/**
 * Drains the local outbox against POST /api/v1/sync/batch — the desktop's
 * half of AD-3. Mirrors the mobile app's src/sync/engine.ts: applied and
 * duplicate both count as synced, rejected is surfaced and never
 * auto-retried, a whole-batch transport failure just bumps attempts and
 * leaves items pending for the next call.
 */
public class SyncService {

    public record SyncSummary(int pushed, int applied, int duplicates, int rejected, String error) {}

    private final OutboxService outbox = new OutboxService();
    private final ApiClient apiClient = new ApiClient();

    public SyncSummary pushOutbox() throws SQLException {
        List<OutboxService.OutboxItem> items = outbox.pending(200);
        if (items.isEmpty()) {
            return new SyncSummary(0, 0, 0, 0, null);
        }

        JSONArray ops = new JSONArray();
        for (OutboxService.OutboxItem item : items) {
            ops.put(new JSONObject()
                    .put("op_id", item.opId())
                    .put("op_type", item.opType())
                    .put("payload", new JSONObject(item.payload()))
                    .put("recorded_at", item.recordedAt()));
        }

        JSONObject response;
        try {
            response = apiClient.pushSyncBatch(ops);
        } catch (ApiClient.ApiException e) {
            for (OutboxService.OutboxItem item : items) {
                outbox.bumpAttempt(item.opId(), e.getMessage());
            }
            return new SyncSummary(items.size(), 0, 0, 0, e.getMessage());
        }

        int applied = 0;
        int duplicates = 0;
        int rejected = 0;
        JSONArray results = response.getJSONArray("results");
        for (int i = 0; i < results.length(); i++) {
            JSONObject result = results.getJSONObject(i);
            String opId = result.getString("op_id");
            String status = result.getString("status");

            switch (status) {
                case "applied" -> {
                    applied++;
                    outbox.markSynced(opId);
                }
                case "duplicate" -> {
                    duplicates++;
                    outbox.markSynced(opId);
                }
                default -> {
                    rejected++;
                    outbox.markRejected(opId, extractErrors(result));
                }
            }
        }

        return new SyncSummary(items.size(), applied, duplicates, rejected, null);
    }

    private String extractErrors(JSONObject result) {
        JSONObject inner = result.optJSONObject("result");
        if (inner != null) {
            JSONArray errors = inner.optJSONArray("errors");
            if (errors != null && !errors.isEmpty()) {
                return errors.getString(0);
            }
        }
        return "Rejected by server.";
    }
}
