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

public class CashPositionController {

    @FXML private TableView<LedgerAccount> table;
    @FXML private TableColumn<LedgerAccount, String> nameColumn;
    @FXML private TableColumn<LedgerAccount, String> heldByColumn;
    @FXML private TableColumn<LedgerAccount, String> balanceColumn;
    @FXML private Label totalLabel;
    @FXML private Label statusLabel;

    private final ReportService reportService = new ReportService();

    @FXML
    private void initialize() {
        nameColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("name"));
        heldByColumn.setCellValueFactory(data -> new SimpleStringProperty(
                "user".equals(data.getValue().getAccountableType()) ? "Agent" : "Branch Office"));
        balanceColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getBalance())));

        refresh();
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading…");
        Task<List<LedgerAccount>> task = new Task<>() {
            @Override
            protected List<LedgerAccount> call() throws Exception {
                return reportService.cashPosition();
            }
        };
        task.setOnSucceeded(event -> {
            List<LedgerAccount> accounts = task.getValue();
            table.setItems(FXCollections.observableArrayList(accounts));
            long total = accounts.stream().mapToLong(LedgerAccount::getBalance).sum();
            totalLabel.setText("Total Cash: " + Money.format(total));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load cash position: " + task.getException().getMessage()));
        new Thread(task, "cash-position-refresh").start();
    }
}
