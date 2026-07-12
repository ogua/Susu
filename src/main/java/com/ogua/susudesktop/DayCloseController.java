package com.ogua.susudesktop;

import db.SessionManager;
import java.util.List;
import java.util.Optional;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.fxml.FXML;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import javafx.scene.control.TextArea;
import javafx.scene.control.TextField;
import javafx.scene.control.TextInputDialog;
import models.AgentDailySummary;
import models.WithdrawalRequest;
import service.AgentDailySummaryService;
import service.WithdrawalService;
import support.Money;

public class DayCloseController {

    @FXML private Label summaryLabel;
    @FXML private TextField declaredCashField;
    @FXML private TextArea notesField;
    @FXML private Label submitStatusLabel;

    @FXML private TableView<WithdrawalRequest> withdrawalsTable;
    @FXML private TableColumn<WithdrawalRequest, String> amountColumn;
    @FXML private TableColumn<WithdrawalRequest, String> reasonColumn;
    @FXML private TableColumn<WithdrawalRequest, String> withdrawalStatusColumn;
    @FXML private Label withdrawalStatusLabel;

    private final AgentDailySummaryService summaryService = new AgentDailySummaryService();
    private final WithdrawalService withdrawalService = new WithdrawalService();

    @FXML
    private void initialize() {
        amountColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getAmount())));
        reasonColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getReason() != null ? data.getValue().getReason() : ""));
        withdrawalStatusColumn.setCellValueFactory(data -> new SimpleStringProperty(data.getValue().getStatus().value()));

        refresh();
    }

    @FXML
    private void refresh() {
        submitStatusLabel.setText("");
        withdrawalStatusLabel.setText("");

        String agentId = currentUserId();
        try {
            AgentDailySummary summary = summaryService.today(agentId);
            long expected = summaryService.expectedCash(agentId, currentUserName());
            summaryLabel.setText(summary.getCollectionsCount() + " collections recorded, total "
                    + Money.format(summary.getCollectionsTotal()) + ". Expected cash in hand: "
                    + Money.format(expected) + ".");
        } catch (Exception e) {
            summaryLabel.setText("Could not load today's summary: " + e.getMessage());
        }

        try {
            List<WithdrawalRequest> pending = withdrawalService.findPending();
            withdrawalsTable.setItems(FXCollections.observableArrayList(pending));
        } catch (Exception e) {
            withdrawalStatusLabel.setText("Could not load withdrawals: " + e.getMessage());
        }
    }

    @FXML
    private void onSubmit() {
        submitStatusLabel.setText("");
        if (declaredCashField.getText().isBlank()) {
            submitStatusLabel.setText("Enter the cash you are holding.");
            return;
        }

        try {
            long declared = Money.toMinorUnits(declaredCashField.getText());
            summaryService.submit(currentUserId(), currentUserName(), declared, emptyToNull(notesField.getText()));
            declaredCashField.clear();
            notesField.clear();
            refresh();
        } catch (Exception e) {
            submitStatusLabel.setText("Could not submit: " + e.getMessage());
        }
    }

    @FXML
    private void onApprove() {
        withStatusHandling(request -> withdrawalService.approve(request.getId(), currentUserId()));
    }

    @FXML
    private void onPay() {
        withStatusHandling(request -> withdrawalService.pay(request.getId(), currentUserId()));
    }

    @FXML
    private void onReject() {
        WithdrawalRequest selected = withdrawalsTable.getSelectionModel().getSelectedItem();
        if (selected == null) {
            withdrawalStatusLabel.setText("Select a withdrawal request first.");
            return;
        }

        TextInputDialog dialog = new TextInputDialog();
        dialog.setTitle("Reject Withdrawal");
        dialog.setHeaderText(null);
        dialog.setContentText("Reason:");
        Optional<String> reason = dialog.showAndWait();
        if (reason.isEmpty() || reason.get().isBlank()) {
            return;
        }

        try {
            withdrawalService.reject(selected.getId(), currentUserId(), reason.get().trim());
            refresh();
        } catch (Exception e) {
            withdrawalStatusLabel.setText("Could not reject: " + e.getMessage());
        }
    }

    private interface WithdrawalAction {
        void apply(WithdrawalRequest request) throws Exception;
    }

    private void withStatusHandling(WithdrawalAction action) {
        withdrawalStatusLabel.setText("");
        WithdrawalRequest selected = withdrawalsTable.getSelectionModel().getSelectedItem();
        if (selected == null) {
            withdrawalStatusLabel.setText("Select a withdrawal request first.");
            return;
        }

        try {
            action.apply(selected);
            refresh();
        } catch (Exception e) {
            withdrawalStatusLabel.setText(e.getMessage());
        }
    }

    private String currentUserId() {
        return SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
    }

    private String currentUserName() {
        return SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getName() : "Agent";
    }

    private String emptyToNull(String value) {
        return (value == null || value.isBlank()) ? null : value.trim();
    }
}
