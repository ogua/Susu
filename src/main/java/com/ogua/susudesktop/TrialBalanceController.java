package com.ogua.susudesktop;

import java.util.List;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import models.LedgerAccount;
import service.ReportService;
import support.Money;

public class TrialBalanceController {

    @FXML private TableView<LedgerAccount> table;
    @FXML private TableColumn<LedgerAccount, String> codeColumn;
    @FXML private TableColumn<LedgerAccount, String> nameColumn;
    @FXML private TableColumn<LedgerAccount, String> typeColumn;
    @FXML private TableColumn<LedgerAccount, String> debitColumn;
    @FXML private TableColumn<LedgerAccount, String> creditColumn;
    @FXML private Label totalDebitsLabel;
    @FXML private Label totalCreditsLabel;
    @FXML private Label balancedLabel;
    @FXML private Label statusLabel;

    private final ReportService reportService = new ReportService();

    @FXML
    private void initialize() {
        codeColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("code"));
        nameColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("name"));
        typeColumn.setCellValueFactory(data -> new SimpleStringProperty(data.getValue().getType().value()));
        debitColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getType().isNormalBalanceDebit() ? Money.format(data.getValue().getBalance()) : ""));
        creditColumn.setCellValueFactory(data -> new SimpleStringProperty(
                !data.getValue().getType().isNormalBalanceDebit() ? Money.format(data.getValue().getBalance()) : ""));

        refresh();
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading…");
        Task<List<LedgerAccount>> task = new Task<>() {
            @Override
            protected List<LedgerAccount> call() throws Exception {
                return reportService.trialBalance();
            }
        };
        task.setOnSucceeded(event -> {
            List<LedgerAccount> accounts = task.getValue();
            table.setItems(FXCollections.observableArrayList(accounts));

            long totalDebits = accounts.stream()
                    .filter(a -> a.getType().isNormalBalanceDebit())
                    .mapToLong(LedgerAccount::getBalance).sum();
            long totalCredits = accounts.stream()
                    .filter(a -> !a.getType().isNormalBalanceDebit())
                    .mapToLong(LedgerAccount::getBalance).sum();

            totalDebitsLabel.setText("Total Debits: " + Money.format(totalDebits));
            totalCreditsLabel.setText("Total Credits: " + Money.format(totalCredits));
            balancedLabel.setText(totalDebits == totalCredits ? "Balanced" : "OUT OF BALANCE");
            balancedLabel.getStyleClass().setAll(totalDebits == totalCredits ? "text-success" : "text-danger");
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load trial balance: " + task.getException().getMessage()));
        new Thread(task, "trial-balance-refresh").start();
    }
}
