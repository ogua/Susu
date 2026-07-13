package service;

import java.util.ArrayList;
import java.util.List;
import models.PaymentIntent;
import org.json.JSONArray;
import org.json.JSONObject;

/** Read-only hybrid-mode payments view — pulls from the server, never writes. */
public class PaymentIntentService {

    private final ApiClient apiClient = new ApiClient();

    public List<PaymentIntent> list() throws ApiClient.ApiException {
        JSONObject response = apiClient.listPaymentIntents();
        JSONArray items = response.optJSONArray("intents");

        List<PaymentIntent> intents = new ArrayList<>();
        if (items == null) {
            return intents;
        }

        for (int i = 0; i < items.length(); i++) {
            intents.add(map(items.getJSONObject(i)));
        }
        return intents;
    }

    private PaymentIntent map(JSONObject json) {
        PaymentIntent intent = new PaymentIntent();
        intent.setId(json.getString("id"));
        intent.setFlow(json.optString("flow", null));
        intent.setChannel(json.optString("channel", null));
        intent.setPhone(json.optString("phone", null));
        intent.setAmount(json.optLong("amount", 0));
        intent.setAmountFormatted(json.optString("amount_formatted", null));
        intent.setStatus(json.optString("status", null));
        intent.setJournalEntryId(json.optString("journal_entry_id", null));
        intent.setCreatedAt(json.optString("created_at", null));
        return intent;
    }
}
