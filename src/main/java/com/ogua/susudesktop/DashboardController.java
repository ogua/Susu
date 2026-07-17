package com.ogua.susudesktop;

import db.AppConfig;
import db.SessionManager;
import enums.AccountStatus;
import java.time.LocalDate;
import java.util.List;
import java.util.Map;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.chart.LineChart;
import javafx.scene.chart.PieChart;
import javafx.scene.chart.XYChart;
import javafx.scene.control.Label;
import javafx.scene.layout.VBox;
import models.AgentDailySummary;
import models.SavingsAccount;
import models.WithdrawalRequest;
import service.AgentDailySummaryService;
import service.ChartOfAccounts;
import service.CustomerService;
import service.OutboxService;
import service.ReportService;
import service.SavingsAccountService;
import service.WithdrawalService;
import support.Money;

public class DashboardController {

    @FXML private Label customersTile;
    @FXML private Label accountsTile;
    @FXML private Label portfolioTile;
    @FXML private Label collectionsTile;
    @FXML private Label closedAccountsTile;
    @FXML private Label avgBalanceTile;
    @FXML private Label pendingWithdrawalsTile;
    @FXML private Label commissionTile;
    @FXML private Label daySheetTile;
    @FXML private VBox pendingSyncCard;
    @FXML private Label pendingSyncTile;
    @FXML private LineChart<String, Number> collectionsChart;
    @FXML private PieChart loanStatusChart;

    private final CustomerService customerService = new CustomerService();
    private final ReportService reportService = new ReportService();
    private final SavingsAccountService accountService = new SavingsAccountService();
    private final AgentDailySummaryService summaryService = new AgentDailySummaryService();
    private final WithdrawalService withdrawalService = new WithdrawalService();
    private final ChartOfAccounts chart = new ChartOfAccounts();
    private final OutboxService outbox = new OutboxService();

    private record Stats(int customerCount, long activeCount, long closedCount, long portfolio,
                          int pendingWithdrawalCount, long pendingWithdrawalAmount,
                          long commissionBalance, AgentDailySummary today, int pendingSync,
                          Map<String, Long> dailyCollections, Map<String, Integer> loanStatusCounts) {}

    @FXML
    private void initialize() {
        boolean syncEnabled = AppConfig.isSyncEnabled();
        pendingSyncCard.setVisible(syncEnabled);
        pendingSyncCard.setManaged(syncEnabled);

        String agentId = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;

        Task<Stats> task = new Task<>() {
            @Override
            protected Stats call() throws Exception {
                int customerCount = customerService.search(null).size();

                long activeCount = 0;
                long closedCount = 0;
                long portfolio = 0;
                for (SavingsAccount account : accountService.findAll()) {
                    if (account.getStatus() == AccountStatus.ACTIVE) {
                        activeCount++;
                        portfolio += account.getBalance();
                    } else if (account.getStatus() == AccountStatus.CLOSED) {
                        closedCount++;
                    }
                }

                List<WithdrawalRequest> pendingWithdrawals = withdrawalService.findPending();
                long pendingWithdrawalAmount = pendingWithdrawals.stream()
                        .mapToLong(WithdrawalRequest::getAmount).sum();

                long commissionBalance = chart.commissionIncome().getBalance();

                AgentDailySummary today = agentId != null ? summaryService.today(agentId) : null;

                int pendingSync = syncEnabled ? outbox.pendingCount() : 0;

                Map<String, Long> dailyCollections = reportService.dailyCollections(30);
                Map<String, Integer> loanStatusCounts = reportService.loanStatusCounts();

                return new Stats(customerCount, activeCount, closedCount, portfolio,
                        pendingWithdrawals.size(), pendingWithdrawalAmount,
                        commissionBalance, today, pendingSync, dailyCollections, loanStatusCounts);
            }
        };

        task.setOnSucceeded(event -> apply(task.getValue()));
        task.setOnFailed(event -> setAllUnavailable());
        new Thread(task, "dashboard-stats").start();
    }

    private void apply(Stats s) {
        customersTile.setText(String.valueOf(s.customerCount()));
        accountsTile.setText(String.valueOf(s.activeCount()));
        portfolioTile.setText(Money.format(s.portfolio()));
        closedAccountsTile.setText(String.valueOf(s.closedCount()));
        avgBalanceTile.setText(Money.format(s.activeCount() > 0 ? s.portfolio() / s.activeCount() : 0));
        pendingWithdrawalsTile.setText(s.pendingWithdrawalCount() + " (" + Money.format(s.pendingWithdrawalAmount()) + ")");
        commissionTile.setText(Money.format(s.commissionBalance()));

        if (s.today() != null) {
            collectionsTile.setText(Money.format(s.today().getCollectionsTotal()) + " (" + s.today().getCollectionsCount() + ")");
            daySheetTile.setText(capitalize(s.today().getStatus().value()));
        } else {
            collectionsTile.setText("—");
            daySheetTile.setText("—");
        }

        pendingSyncTile.setText(String.valueOf(s.pendingSync()));

        applyCharts(s);
    }

    private void applyCharts(Stats s) {
        XYChart.Series<String, Number> series = new XYChart.Series<>();
        LocalDate start = LocalDate.now().minusDays(29);
        for (int offset = 0; offset < 30; offset++) {
            String date = start.plusDays(offset).toString();
            long minorUnits = s.dailyCollections().getOrDefault(date, 0L);
            // Axis reads in major units (GHS) or the scale would be off by 100x.
            series.getData().add(new XYChart.Data<>(date.substring(5), minorUnits / 100.0));
        }
        collectionsChart.setData(FXCollections.observableArrayList(List.of(series)));

        loanStatusChart.setData(FXCollections.observableArrayList(
                s.loanStatusCounts().entrySet().stream()
                        .map(entry -> new PieChart.Data(
                                entry.getKey().replace('_', ' ') + " (" + entry.getValue() + ")",
                                entry.getValue()))
                        .toList()));
    }

    private void setAllUnavailable() {
        for (Label label : List.of(customersTile, accountsTile, portfolioTile, collectionsTile,
                closedAccountsTile, avgBalanceTile, pendingWithdrawalsTile, commissionTile,
                daySheetTile, pendingSyncTile)) {
            label.setText("—");
        }
    }

    private String capitalize(String value) {
        return value.isEmpty() ? value : Character.toUpperCase(value.charAt(0)) + value.substring(1);
    }
}
