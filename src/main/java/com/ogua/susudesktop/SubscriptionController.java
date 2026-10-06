package com.ogua.susudesktop;

import java.awt.Desktop;
import java.net.URI;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.control.Button;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import org.json.JSONArray;
import org.json.JSONObject;
import service.ApiClient;
import support.Money;

/**
 * Company admins in hybrid mode: the company's SusuApp plan, usage against
 * its limits and unpaid invoices. Paying opens Paystack in the browser; the
 * admin then clicks "Check payment" so the server confirms it with Paystack.
 * Online only — there is no offline billing.
 */
public class SubscriptionController {

    @FXML private Label planLabel;
    @FXML private Label statusLabel;
    @FXML private Label renewLabel;
    @FXML private Label usageLabel;
    @FXML private Label supportLabel;
    @FXML private Label messageLabel;
    @FXML private Button payButton;
    @FXML private Button checkButton;
    @FXML private TableView<JSONObject> invoicesTable;
    @FXML private TableColumn<JSONObject, String> numberColumn;
    @FXML private TableColumn<JSONObject, String> amountColumn;
    @FXML private TableColumn<JSONObject, String> periodColumn;
    @FXML private TableColumn<JSONObject, String> dueColumn;
    @FXML private TableColumn<JSONObject, String> stateColumn;

    private final ApiClient apiClient = new ApiClient();

    @FXML
    private void initialize() {
        numberColumn.setCellValueFactory(data -> new SimpleStringProperty(data.getValue().optString("number")));
        amountColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().optLong("amount"), data.getValue().optString("currency", Money.DEFAULT_CURRENCY))));
        periodColumn.setCellValueFactory(data -> new SimpleStringProperty(
                date(data.getValue().optString("period_start")) + " – " + date(data.getValue().optString("period_end"))));
        dueColumn.setCellValueFactory(data -> new SimpleStringProperty(date(data.getValue().optString("due_at"))));
        stateColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().optBoolean("is_overdue") ? "Overdue" : "Due"));

        payButton.disableProperty().bind(invoicesTable.getSelectionModel().selectedItemProperty().isNull());
        checkButton.disableProperty().bind(invoicesTable.getSelectionModel().selectedItemProperty().isNull());

        refresh();
    }

    @FXML
    private void refresh() {
        messageLabel.setText("Loading…");
        run(() -> apiClient.getSubscription(), this::show, "Could not load the subscription");
    }

    @FXML
    private void onPay() {
        JSONObject invoice = invoicesTable.getSelectionModel().getSelectedItem();
        if (invoice == null) {
            return;
        }
        messageLabel.setText("Opening Paystack…");
        run(() -> apiClient.startInvoiceCheckout(invoice.getString("id")), checkout -> {
            try {
                Desktop.getDesktop().browse(new URI(checkout.getString("authorization_url")));
                messageLabel.setText("Complete the payment in your browser, then click \"Check payment\".");
            } catch (Exception e) {
                messageLabel.setText("Open this link to pay: " + checkout.getString("authorization_url"));
            }
        }, "Could not start the payment");
    }

    @FXML
    private void onCheckPayment() {
        JSONObject invoice = invoicesTable.getSelectionModel().getSelectedItem();
        if (invoice == null) {
            return;
        }
        messageLabel.setText("Checking with Paystack…");
        String invoiceId = invoice.getString("id");
        run(() -> apiClient.verifyInvoicePayment(invoiceId), response -> {
            show(response);
            boolean stillOpen = false;
            JSONArray open = response.getJSONObject("data").optJSONArray("open_invoices");
            for (int i = 0; open != null && i < open.length(); i++) {
                stillOpen |= invoiceId.equals(open.getJSONObject(i).optString("id"));
            }
            messageLabel.setText(stillOpen
                    ? "Paystack has not confirmed this payment yet. If you paid, check again in a minute."
                    : "Payment received — thank you.");
        }, "Could not check the payment");
    }

    private void show(JSONObject response) {
        JSONObject data = response.getJSONObject("data");
        JSONObject plan = data.optJSONObject("plan");

        if (plan == null) {
            planLabel.setText("No plan yet — contact the SusuApp team to choose one.");
            statusLabel.setText("");
            renewLabel.setText("");
        } else {
            String period = "yearly".equals(plan.optString("billing_period")) ? "year" : "month";
            planLabel.setText(plan.optString("name") + " — " + Money.format(plan.optLong("price_amount"), plan.optString("currency", Money.DEFAULT_CURRENCY)) + " / " + period);
            String status = data.optString("status", "");
            statusLabel.setText(switch (status) {
                case "trialing" -> "Free trial";
                case "past_due" -> "Overdue — pay to avoid suspension";
                case "cancelled" -> "Cancelled";
                default -> "Active";
            });
            statusLabel.getStyleClass().removeAll("text-danger", "text-muted");
            statusLabel.getStyleClass().add("past_due".equals(status) ? "text-danger" : "text-muted");
            String trialEnds = data.optString("trial_ends_at", "");
            renewLabel.setText(!trialEnds.isEmpty() && !"null".equals(trialEnds)
                    ? "Trial ends " + date(trialEnds)
                    : "Renews " + date(data.optString("current_period_end", "")));
        }

        JSONObject usage = data.optJSONObject("usage");
        StringBuilder usageText = new StringBuilder();
        for (String resource : new String[] {"branches", "staff", "customers"}) {
            JSONObject figure = usage != null ? usage.optJSONObject(resource) : null;
            if (figure == null) {
                continue;
            }
            Object limit = figure.opt("limit");
            usageText.append(usageText.isEmpty() ? "" : "   ·   ")
                    .append(Character.toUpperCase(resource.charAt(0))).append(resource.substring(1)).append(": ")
                    .append(figure.optInt("used")).append(" / ")
                    .append(limit == null || JSONObject.NULL.equals(limit) ? "unlimited" : limit.toString());
        }
        usageLabel.setText(usageText.toString());

        JSONObject support = data.optJSONObject("support");
        String email = support != null ? support.optString("email", "") : "";
        String phone = support != null ? support.optString("phone", "") : "";
        boolean hasEmail = !email.isEmpty() && !"null".equals(email);
        boolean hasPhone = !phone.isEmpty() && !"null".equals(phone);
        supportLabel.setText(hasEmail || hasPhone
                ? "Questions? Contact the SusuApp team" + (hasEmail ? " at " + email : "") + (hasPhone ? (hasEmail ? " or " : " on ") + phone : "") + "."
                : "");

        JSONArray invoices = data.optJSONArray("open_invoices");
        var rows = FXCollections.<JSONObject>observableArrayList();
        for (int i = 0; invoices != null && i < invoices.length(); i++) {
            rows.add(invoices.getJSONObject(i));
        }
        invoicesTable.setItems(rows);
        messageLabel.setText(rows.isEmpty() ? "Nothing to pay — you're up to date." : "");
    }

    private static String date(String iso) {
        return iso == null || iso.length() < 10 || "null".equals(iso) ? "—" : iso.substring(0, 10);
    }

    private interface ApiCall {
        JSONObject call() throws Exception;
    }

    private void run(ApiCall call, java.util.function.Consumer<JSONObject> onSuccess, String failurePrefix) {
        Task<JSONObject> task = new Task<>() {
            @Override
            protected JSONObject call() throws Exception {
                return call.call();
            }
        };
        task.setOnSucceeded(event -> onSuccess.accept(task.getValue()));
        task.setOnFailed(event -> messageLabel.setText(failurePrefix + ": " + task.getException().getMessage()));
        new Thread(task, "subscription-task").start();
    }
}
