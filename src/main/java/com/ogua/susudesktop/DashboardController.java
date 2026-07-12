package com.ogua.susudesktop;

import db.SessionManager;
import enums.AccountStatus;
import javafx.fxml.FXML;
import javafx.scene.control.Label;
import models.AgentDailySummary;
import models.SavingsAccount;
import service.AgentDailySummaryService;
import service.CustomerService;
import service.SavingsAccountService;
import support.Money;

public class DashboardController {

    @FXML private Label customersTile;
    @FXML private Label accountsTile;
    @FXML private Label portfolioTile;
    @FXML private Label collectionsTile;

    private final CustomerService customerService = new CustomerService();
    private final SavingsAccountService accountService = new SavingsAccountService();
    private final AgentDailySummaryService summaryService = new AgentDailySummaryService();

    @FXML
    private void initialize() {
        try {
            customersTile.setText(String.valueOf(customerService.search(null).size()));
        } catch (Exception e) {
            customersTile.setText("—");
        }

        try {
            long activeCount = 0;
            long portfolio = 0;
            for (SavingsAccount account : accountService.findAll()) {
                if (account.getStatus() == AccountStatus.ACTIVE) {
                    activeCount++;
                }
                portfolio += account.getBalance();
            }
            accountsTile.setText(String.valueOf(activeCount));
            portfolioTile.setText(Money.format(portfolio));
        } catch (Exception e) {
            accountsTile.setText("—");
            portfolioTile.setText("—");
        }

        try {
            String agentId = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
            if (agentId != null) {
                AgentDailySummary summary = summaryService.today(agentId);
                collectionsTile.setText(Money.format(summary.getCollectionsTotal())
                        + " (" + summary.getCollectionsCount() + ")");
            } else {
                collectionsTile.setText("—");
            }
        } catch (Exception e) {
            collectionsTile.setText("—");
        }
    }
}
