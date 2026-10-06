package com.ogua.susudesktop;

import db.AppConfig;
import db.DatabaseConnection;
import db.SessionManager;
import java.io.IOException;
import java.util.List;
import javafx.animation.Timeline;
import javafx.animation.KeyFrame;
import javafx.application.Platform;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.fxml.FXMLLoader;
import javafx.scene.Parent;
import javafx.scene.control.Button;
import javafx.scene.control.Label;
import javafx.scene.layout.StackPane;
import javafx.stage.Stage;
import javafx.util.Duration;
import models.LocalUser;
import org.json.JSONArray;
import org.json.JSONObject;
import service.ApiClient;
import service.FixedDepositService;
import service.LicenseManager;
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
    @FXML private Label licenseWarningLabel;
    @FXML private Label announcementBanner;
    @FXML private StackPane contentArea;

    @FXML private Button navDashboard;
    @FXML private Button navCustomers;
    @FXML private Button navAccounts;
    @FXML private Button navLoans;
    @FXML private Button navGroups;
    @FXML private Button navLoanGroups;
    @FXML private Button navGroupLoans;
    @FXML private Button navDayClose;
    @FXML private Button navPayments;
    @FXML private Button navTrialBalance;
    @FXML private Button navDefaulters;
    @FXML private Button navCashPosition;
    @FXML private Button navCollections;
    @FXML private Button navLoanPortfolio;
    @FXML private Button navAgentPerformance;
    @FXML private Button navWithdrawalsReport;
    @FXML private Button navGroupsReport;
    @FXML private Button navCustomerBalances;
    @FXML private Button navChartOfAccounts;
    @FXML private Button navProducts;
    @FXML private Button navEngineSwitch;
    @FXML private Button navGoOnline;

    private final OutboxService outbox = new OutboxService();
    private final SyncService syncService = new SyncService();
    private final LoanService loanService = new LoanService();
    private final FixedDepositService fixedDepositService = new FixedDepositService();
    private Timeline licenseWatch;

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

        // Engine-switch/go-online are admin-level, install-wide decisions —
        // hidden from field agents/branch managers. Go Online only makes
        // sense once, before hybrid mode is already on.
        boolean isCompanyAdmin = user != null && "company_admin".equals(user.getRole());
        // Product management mirrors the web admin's product resources —
        // back-office only, not a field-agent concern.
        boolean isBackOffice = isCompanyAdmin
                || (user != null && "branch_manager".equals(user.getRole()));
        navProducts.setVisible(isBackOffice);
        navProducts.setManaged(isBackOffice);
        navEngineSwitch.setVisible(isCompanyAdmin);
        navEngineSwitch.setManaged(isCompanyAdmin);
        boolean showGoOnline = isCompanyAdmin && !AppConfig.isSyncEnabled();
        navGoOnline.setVisible(showGoOnline);
        navGoOnline.setManaged(showGoOnline);

        refreshSyncStatus();
        pullProductsInBackground();
        loadAnnouncementsInBackground();
        flagArrearsInBackground();
        matureFixedDepositsInBackground();
        startLicenseWatch();

        showDashboard();
    }

    /**
     * Re-checks the license every 6 hours for as long as this session stays
     * open (mirrors Oguaschoolz's {@code LicenseWatch}) — a session that
     * outlasts its grace period gets locked out mid-use, not just at the
     * next launch. An immediate check also drives the expiry-warning banner.
     */
    private void startLicenseWatch() {
        Runnable check = () -> {
            Task<LicenseManager.LicenseStatus> task = new Task<>() {
                @Override
                protected LicenseManager.LicenseStatus call() {
                    return LicenseManager.getLicenseStatus();
                }
            };
            task.setOnSucceeded(event -> applyLicenseStatus(task.getValue()));
            new Thread(task, "license-watch-check").start();
        };

        check.run();
        licenseWatch = new Timeline(new KeyFrame(Duration.hours(6), event -> check.run()));
        licenseWatch.setCycleCount(Timeline.INDEFINITE);
        licenseWatch.play();
    }

    private void applyLicenseStatus(LicenseManager.LicenseStatus status) {
        if (status == LicenseManager.LicenseStatus.EXPIRED
                || status == LicenseManager.LicenseStatus.MISSING
                || status == LicenseManager.LicenseStatus.TAMPERED) {
            if (licenseWatch != null) {
                licenseWatch.stop();
            }
            SessionManager.clearSession();
            LicenseManager.pendingStatus = status;
            Navigator.showLicense((Stage) userLabel.getScene().getWindow());
            return;
        }

        if (status == LicenseManager.LicenseStatus.GRACE_PERIOD) {
            licenseWarningLabel.setText("License expired — renew within " + LicenseManager.GRACE_DAYS
                    + " days of expiry to avoid a lockout.");
            licenseWarningLabel.setVisible(true);
            licenseWarningLabel.setManaged(true);
        } else if (LicenseManager.shouldShowExpiryWarning()) {
            long daysLeft = LicenseManager.getDaysUntilExpiry();
            licenseWarningLabel.setText("License expires in " + daysLeft + " day(s) — renew soon.");
            licenseWarningLabel.setVisible(true);
            licenseWarningLabel.setManaged(true);
        } else {
            licenseWarningLabel.setVisible(false);
            licenseWarningLabel.setManaged(false);
        }
    }

    /**
     * Shows the most important running platform announcement (maintenance,
     * new features) under the top bar. Hybrid mode only; silent when the
     * server can't be reached.
     */
    private void loadAnnouncementsInBackground() {
        if (!AppConfig.isSyncEnabled()) {
            return;
        }
        Thread thread = new Thread(() -> {
            try {
                JSONArray announcements = new ApiClient().listAnnouncements().optJSONArray("data");
                if (announcements == null || announcements.isEmpty()) {
                    return;
                }
                JSONObject first = announcements.getJSONObject(0);
                String text = first.optString("title") + " — " + first.optString("body")
                        + (announcements.length() > 1 ? "  (+" + (announcements.length() - 1) + " more)" : "");
                String styleClass = switch (first.optString("level")) {
                    case "critical" -> "text-danger";
                    case "warning" -> "text-warning";
                    default -> "body-text";
                };
                Platform.runLater(() -> {
                    announcementBanner.setText(text);
                    announcementBanner.getStyleClass().add(styleClass);
                    announcementBanner.setVisible(true);
                    announcementBanner.setManaged(true);
                });
            } catch (Exception ignored) {
                // Best-effort: offline or signed out of the server — show nothing.
            }
        }, "announcements-startup");
        thread.setDaemon(true);
        thread.start();
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

    /**
     * This app has no background scheduler, so {@link FixedDepositService#matureFixedDeposits()}
     * (the standalone mirror of the backend's scheduled fixed-deposit maturity
     * command) runs once per launch instead, same pattern as {@link #flagArrearsInBackground()}.
     */
    private void matureFixedDepositsInBackground() {
        Thread thread = new Thread(() -> {
            try {
                fixedDepositService.matureFixedDeposits();
            } catch (Exception ignored) {
                // Best-effort: a failure here shouldn't block the app from opening.
            }
        }, "fixed-deposit-maturity-startup");
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
    private void showLoanGroups() {
        load("loan-groups-view.fxml", navLoanGroups);
    }

    @FXML
    private void showGroupLoans() {
        load("group-loans-view.fxml", navGroupLoans);
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
    private void showCollectionsReport() {
        load("collections-report-view.fxml", navCollections);
    }

    @FXML
    private void showLoanPortfolio() {
        load("loan-portfolio-view.fxml", navLoanPortfolio);
    }

    @FXML
    private void showAgentPerformance() {
        load("agent-performance-view.fxml", navAgentPerformance);
    }

    @FXML
    private void showWithdrawalsReport() {
        load("withdrawals-report-view.fxml", navWithdrawalsReport);
    }

    @FXML
    private void showGroupsReport() {
        load("groups-report-view.fxml", navGroupsReport);
    }

    @FXML
    private void showCustomerBalances() {
        load("customer-balances-view.fxml", navCustomerBalances);
    }

    @FXML
    private void showChartOfAccounts() {
        load("chart-of-accounts-view.fxml", navChartOfAccounts);
    }

    @FXML
    private void showProducts() {
        load("products-view.fxml", navProducts);
    }

    @FXML
    private void showEngineSwitch() {
        load("engine-switch-view.fxml", navEngineSwitch);
    }

    @FXML
    private void showGoOnline() {
        load("go-online-view.fxml", navGoOnline);
    }

    @FXML
    private void onLogout() {
        if (licenseWatch != null) {
            licenseWatch.stop();
        }
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

        for (Button nav : List.of(navDashboard, navCustomers, navAccounts, navLoans, navGroups,
                navLoanGroups, navGroupLoans, navDayClose, navPayments,
                navTrialBalance, navDefaulters, navCashPosition, navCollections, navLoanPortfolio,
                navAgentPerformance, navWithdrawalsReport, navGroupsReport, navCustomerBalances,
                navChartOfAccounts, navProducts, navEngineSwitch, navGoOnline)) {
            nav.getStyleClass().remove("nav-button-active");
        }
        activeNav.getStyleClass().add("nav-button-active");
    }
}
