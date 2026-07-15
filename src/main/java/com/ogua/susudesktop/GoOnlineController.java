package com.ogua.susudesktop;

import db.AppConfig;
import java.util.List;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.control.Button;
import javafx.scene.control.Label;
import javafx.scene.control.PasswordField;
import javafx.scene.control.TextField;
import javafx.stage.Stage;
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
                    apiClient.login(email, password, "susudesktop-go-online");
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
            statusLabel.setText(ex != null ? ex.getMessage() : "Could not go online.");
        });

        new Thread(task, "go-online-task").start();
    }
}
