package com.ogua.susudesktop;

import db.AppConfig;
import db.DatabaseConnection;
import db.SessionManager;
import java.io.IOException;
import java.util.List;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.fxml.FXMLLoader;
import javafx.scene.Parent;
import javafx.scene.control.Button;
import javafx.scene.control.Label;
import javafx.scene.layout.StackPane;
import javafx.stage.Stage;
import models.LocalUser;
import service.LoanService;
import service.OutboxService;
import service.SyncService;

/**
 * App shell: persistent sidebar + top bar, swapping only the center content
 * area between screens so navigation state (and the Alpine-equivalent — here,
 * just Java field state) never gets torn down between clicks.
 */
public class MainController {

    @FXML private Label userLabel;
    @FXML private Label modeLabel;
    @FXML private Label storageLabel;
    @FXML private Label syncStatusLabel;
    @FXML private Button syncNowButton;
    @FXML private StackPane contentArea;

    @FXML private Button navDashboard;
    @FXML private Button navCustomers;
    @FXML private Button navAccounts;
    @FXML private Button navLoans;
    @FXML private Button navGroups;
    @FXML private Button navDayClose;
    @FXML private Button navPayments;
    @FXML private Button navTrialBalance;
    @FXML private Button navDefaulters;
    @FXML private Button navCashPosition;

    private final OutboxService outbox = new OutboxService();
    private final SyncService syncService = new SyncService();
    private final LoanService loanService = new LoanService();

    @FXML
    private void initialize() {
        LocalUser user = SessionManager.getCurrentUser();
        if (user != null) {
            userLabel.setText(user.getName() + " (" + user.getRole().replace('_', ' ') + ")");
        }

        modeLabel.setText(AppConfig.isSyncEnabled() ? "Hybrid — syncs with server" : "Standalone — offline");
        try {
            storageLabel.setText("Storage: " + DatabaseConnection.getActiveProvider().getType().toUpperCase());
        } catch (Exception e) {
            storageLabel.setText("Storage: unknown");
        }

        syncNowButton.setVisible(AppConfig.isSyncEnabled());
        syncNowButton.setManaged(AppConfig.isSyncEnabled());
        // Payments are only ever initiated from mobile/web; standalone desktops
        // have no server to pull them from at all.
        navPayments.setVisible(AppConfig.isSyncEnabled());
        navPayments.setManaged(AppConfig.isSyncEnabled());
        refreshSyncStatus();
        pullProductsInBackground();
        flagArrearsInBackground();

        showDashboard();
    }

    /** Best-effort catalogue refresh on startup, so a freshly opened session has
     * the company's real products before the user tries to open an account. */
    private void pullProductsInBackground() {
        if (!AppConfig.isSyncEnabled()) {
            return;
        }
        Thread thread = new Thread(syncService::pullProducts, "product-pull-startup");
        thread.setDaemon(true);
        thread.start();
    }

    /**
     * This app has no background scheduler, so {@link LoanService#flagArrears()}
     * (the standalone mirror of the backend's daily {@code loans:flag-arrears}
     * command) runs once per launch instead — the Defaulters report is only
     * ever as fresh as the last time someone opened the app.
     */
    private void flagArrearsInBackground() {
        Thread thread = new Thread(() -> {
            try {
                loanService.flagArrears();
            } catch (Exception ignored) {
                // Best-effort: a failure here shouldn't block the app from opening.
            }
        }, "loan-arrears-startup");
        thread.setDaemon(true);
        thread.start();
    }

    private void refreshSyncStatus() {
        if (!AppConfig.isSyncEnabled()) {
            syncStatusLabel.setText("");
            return;
        }

        try {
            int pending = outbox.pendingCount();
            syncStatusLabel.setText(pending == 0 ? "Synced" : pending + " pending sync");
        } catch (Exception e) {
            syncStatusLabel.setText("Sync status unknown");
        }
    }

    @FXML
    private void onSyncNow() {
        syncNowButton.setDisable(true);
        syncStatusLabel.setText("Syncing…");

        Task<SyncService.SyncSummary> task = new Task<>() {
            @Override
            protected SyncService.SyncSummary call() throws Exception {
                return syncService.syncNow();
            }
        };

        task.setOnSucceeded(event -> {
            SyncService.SyncSummary summary = task.getValue();
            syncNowButton.setDisable(false);
            if (summary.error() != null) {
                syncStatusLabel.setText("Sync failed: " + summary.error());
            } else if (summary.pushed() == 0) {
                syncStatusLabel.setText("Synced");
            } else {
                syncStatusLabel.setText(summary.applied() + summary.duplicates() + " synced, "
                        + summary.rejected() + " rejected");
            }
            refreshSyncStatus();
        });
        task.setOnFailed(event -> {
            syncNowButton.setDisable(false);
            syncStatusLabel.setText("Sync failed unexpectedly.");
        });

        new Thread(task, "sync-now-task").start();
    }

    @FXML
    private void showDashboard() {
        load("dashboard-view.fxml", navDashboard);
    }

    @FXML
    private void showCustomers() {
        load("customers-view.fxml", navCustomers);
    }

    @FXML
    private void showAccounts() {
        load("savings-accounts-view.fxml", navAccounts);
    }

    @FXML
    private void showLoans() {
        load("loans-view.fxml", navLoans);
    }

    @FXML
    private void showGroups() {
        load("groups-view.fxml", navGroups);
    }

    @FXML
    private void showDayClose() {
        load("day-close-view.fxml", navDayClose);
    }

    @FXML
    private void showPayments() {
        load("payment-intents-view.fxml", navPayments);
    }

    @FXML
    private void showTrialBalance() {
        load("trial-balance-view.fxml", navTrialBalance);
    }

    @FXML
    private void showDefaulters() {
        load("defaulters-view.fxml", navDefaulters);
    }

    @FXML
    private void showCashPosition() {
        load("cash-position-view.fxml", navCashPosition);
    }

    @FXML
    private void onLogout() {
        SessionManager.clearSession();
        Navigator.showLogin((Stage) userLabel.getScene().getWindow());
    }

    private void load(String fxml, Button activeNav) {
        try {
            FXMLLoader loader = new FXMLLoader(MainController.class.getResource(fxml));
            Parent view = loader.load();
            contentArea.getChildren().setAll(view);
            Animations.fadeInScreen(view);
        } catch (IOException e) {
            throw new IllegalStateException("Could not load view " + fxml + ": " + e.getMessage(), e);
        }

        for (Button nav : List.of(navDashboard, navCustomers, navAccounts, navLoans, navGroups, navDayClose, navPayments,
                navTrialBalance, navDefaulters, navCashPosition)) {
            nav.getStyleClass().remove("nav-button-active");
        }
        activeNav.getStyleClass().add("nav-button-active");
    }
}
