package com.ogua.susudesktop;

import java.util.ArrayList;
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
    private List<LedgerAccount> current = List.of();

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
            current = task.getValue();
            table.setItems(FXCollections.observableArrayList(current));
            long total = current.stream().mapToLong(LedgerAccount::getBalance).sum();
            totalLabel.setText("Total Cash: " + Money.format(total));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load cash position: " + task.getException().getMessage()));
        new Thread(task, "cash-position-refresh").start();
    }

    @FXML
    private void exportPdf() {
        ReportExporter.exportPdf(table.getScene().getWindow(), "cash-position",
                "Cash Position Report", List.of(), headers(), rows(), statusLabel);
    }

    @FXML
    private void exportCsv() {
        ReportExporter.exportCsv(table.getScene().getWindow(), "cash-position",
                headers(), rows(), statusLabel);
    }

    private List<String> headers() {
        return List.of("Account", "Held By", "Balance");
    }

    private List<List<String>> rows() {
        List<List<String>> rows = new ArrayList<>();
        for (LedgerAccount account : current) {
            rows.add(List.of(account.getName(),
                    "user".equals(account.getAccountableType()) ? "Agent" : "Branch Office",
                    Money.format(account.getBalance())));
        }
        return rows;
    }
}
