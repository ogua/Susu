package com.ogua.susudesktop;

import db.AppConfig;
import java.util.List;
import java.util.Optional;
import javafx.beans.binding.Bindings;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.Node;
import javafx.scene.control.Button;
import javafx.scene.control.ButtonBar;
import javafx.scene.control.ButtonType;
import javafx.scene.control.Dialog;
import javafx.scene.control.Label;
import javafx.scene.control.PasswordField;
import javafx.scene.control.TextField;
import javafx.scene.layout.VBox;
import javafx.stage.Stage;
import org.json.JSONObject;
import service.ApiClient;
import service.GoOnlineService;
import service.OutboxService;
import service.SyncService;

public class GoOnlineController {

    @FXML private TextField apiBaseUrl;
    @FXML private TextField emailField;
    @FXML private PasswordField passwordField;
    @FXML private Label progressLabel;
    @FXML private Label statusLabel;
    @FXML private Button goOnlineButton;

    private final ApiClient apiClient = new ApiClient();
    private final GoOnlineService goOnlineService = new GoOnlineService();
    private final SyncService syncService = new SyncService();
    private final OutboxService outbox = new OutboxService();

    @FXML
    private void onGoOnline() {
        statusLabel.setText("");
        progressLabel.setText("");

        String url = apiBaseUrl.getText().trim().replaceAll("/+$", "");
        String email = emailField.getText().trim();
        String password = passwordField.getText();
        if (url.isEmpty() || email.isEmpty() || password.isEmpty()) {
            statusLabel.setText("Enter the server URL, email, and password.");
            return;
        }

        goOnlineButton.setDisable(true);

        Task<Void> task = new Task<>() {
            @Override
            protected Void call() throws Exception {
                AppConfig.set("api.base_url", url);

                updateMessage("Signing in…");
                try {
                    JSONObject session = apiClient.login(email, password, "susudesktop-go-online");
                    if (session.getJSONObject("user").optBoolean("must_change_password", false)) {
                        throw new PasswordChangeRequiredException();
                    }
                } catch (ApiClient.ApiException e) {
                    throw new IllegalStateException("Could not sign in: " + e.getMessage());
                }

                AppConfig.set("sync.enabled", "true");

                updateMessage("Queuing local history…");
                List<GoOnlineService.BackfillResult> backfill = goOnlineService.backfillOutbox();
                int totalQueued = backfill.stream().mapToInt(GoOnlineService.BackfillResult::queued).sum();
                updateMessage("Queued " + totalQueued + " historical event(s). Uploading…");

                syncService.pullProducts();
                int rejected = 0;
                while (outbox.pendingCount() > 0) {
                    SyncService.SyncSummary summary = syncService.pushOutbox();
                    if (summary.error() != null) {
                        throw new IllegalStateException("Upload stopped: " + summary.error());
                    }
                    rejected += summary.rejected();
                    updateMessage("Uploaded " + (summary.applied() + summary.duplicates())
                            + " event(s) this batch" + (rejected > 0 ? " (" + rejected + " rejected so far)" : "") + "…");
                }

                if (rejected > 0) {
                    updateMessage(rejected + " event(s) were rejected by the server — check the Sync screen for details.");
                }
                return null;
            }
        };

        task.messageProperty().addListener((obs, old, msg) -> progressLabel.setText(msg));
        task.setOnSucceeded(event -> Navigator.showMain((Stage) goOnlineButton.getScene().getWindow()));
        task.setOnFailed(event -> {
            goOnlineButton.setDisable(false);
            Throwable ex = task.getException();
            if (ex instanceof PasswordChangeRequiredException) {
                promptForNewPassword(password);
                return;
            }
            statusLabel.setText(ex != null ? ex.getMessage() : "Could not go online.");
        });

        new Thread(task, "go-online-task").start();
    }

    /**
     * The server account is on a temporary password: ask for a new one, set
     * it on the server, then retry going online with it. The offline login
     * password on this computer is not changed.
     */
    private void promptForNewPassword(String temporaryPassword) {
        Dialog<String> dialog = new Dialog<>();
        dialog.setTitle("Choose a new password");
        dialog.setHeaderText("Your server account uses a temporary password. Choose your own to continue.");
        ButtonType save = new ButtonType("Save password", ButtonBar.ButtonData.OK_DONE);
        dialog.getDialogPane().getButtonTypes().addAll(save, ButtonType.CANCEL);

        PasswordField newPassword = new PasswordField();
        newPassword.setPromptText("New password (at least 8 characters)");
        PasswordField confirmation = new PasswordField();
        confirmation.setPromptText("Confirm new password");
        VBox content = new VBox(8, newPassword, confirmation);
        dialog.getDialogPane().setContent(content);

        Node saveButton = dialog.getDialogPane().lookupButton(save);
        saveButton.disableProperty().bind(Bindings.createBooleanBinding(
                () -> newPassword.getText().length() < 8 || !newPassword.getText().equals(confirmation.getText()),
                newPassword.textProperty(), confirmation.textProperty()));
        dialog.setResultConverter(button -> button == save ? newPassword.getText() : null);

        Optional<String> chosen = dialog.showAndWait();
        if (chosen.isEmpty()) {
            statusLabel.setText("Choose a new password to go online.");
            return;
        }

        goOnlineButton.setDisable(true);
        Task<Void> change = new Task<>() {
            @Override
            protected Void call() throws Exception {
                apiClient.changePassword(temporaryPassword, chosen.get());
                return null;
            }
        };
        change.setOnSucceeded(event -> {
            passwordField.setText(chosen.get());
            onGoOnline();
        });
        change.setOnFailed(event -> {
            goOnlineButton.setDisable(false);
            Throwable ex = change.getException();
            statusLabel.setText(ex != null ? ex.getMessage() : "Could not change the password.");
        });
        new Thread(change, "change-password-task").start();
    }

    /** Signals that the server account must replace its temporary password first. */
    private static final class PasswordChangeRequiredException extends Exception {
        PasswordChangeRequiredException() {
            super("Your server account needs a new password.");
        }
    }
}
