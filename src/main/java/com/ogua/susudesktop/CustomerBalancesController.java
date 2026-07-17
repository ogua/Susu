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
import javafx.scene.control.cell.PropertyValueFactory;
import models.CustomerBalanceRow;
import service.ReportService;
import support.Money;

public class CustomerBalancesController {

    @FXML private TableView<CustomerBalanceRow> table;
    @FXML private TableColumn<CustomerBalanceRow, String> accountColumn;
    @FXML private TableColumn<CustomerBalanceRow, String> customerColumn;
    @FXML private TableColumn<CustomerBalanceRow, String> phoneColumn;
    @FXML private TableColumn<CustomerBalanceRow, String> productColumn;
    @FXML private TableColumn<CustomerBalanceRow, String> agentColumn;
    @FXML private TableColumn<CustomerBalanceRow, String> statusColumn;
    @FXML private TableColumn<CustomerBalanceRow, String> balanceColumn;
    @FXML private Label totalLabel;
    @FXML private Label statusLabel;

    private final ReportService reportService = new ReportService();
    private List<CustomerBalanceRow> current = List.of();

    @FXML
    private void initialize() {
        accountColumn.setCellValueFactory(new PropertyValueFactory<>("accountNumber"));
        customerColumn.setCellValueFactory(new PropertyValueFactory<>("customerName"));
        phoneColumn.setCellValueFactory(new PropertyValueFactory<>("customerPhone"));
        productColumn.setCellValueFactory(new PropertyValueFactory<>("productName"));
        agentColumn.setCellValueFactory(new PropertyValueFactory<>("agentName"));
        statusColumn.setCellValueFactory(new PropertyValueFactory<>("status"));
        balanceColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getBalance())));

        refresh();
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading…");
        Task<List<CustomerBalanceRow>> task = new Task<>() {
            @Override
            protected List<CustomerBalanceRow> call() throws Exception {
                return reportService.customerBalances();
            }
        };
        task.setOnSucceeded(event -> {
            current = task.getValue();
            table.setItems(FXCollections.observableArrayList(current));
            long total = current.stream().mapToLong(CustomerBalanceRow::getBalance).sum();
            totalLabel.setText(current.size() + " accounts · Total " + Money.format(total));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load balances: " + task.getException().getMessage()));
        new Thread(task, "customer-balances-refresh").start();
    }

    @FXML
    private void exportPdf() {
        ReportExporter.exportPdf(table.getScene().getWindow(), "customer-balances",
                "Customer Balances", List.of("As at: " + java.time.LocalDate.now()),
                headers(), rows(), statusLabel);
    }

    @FXML
    private void exportCsv() {
        ReportExporter.exportCsv(table.getScene().getWindow(), "customer-balances",
                headers(), rows(), statusLabel);
    }

    private List<String> headers() {
        return List.of("Account #", "Customer", "Phone", "Product", "Agent", "Status", "Balance");
    }

    private List<List<String>> rows() {
        List<List<String>> rows = new ArrayList<>();
        for (CustomerBalanceRow row : current) {
            rows.add(List.of(row.getAccountNumber(), row.getCustomerName(), row.getCustomerPhone(),
                    row.getProductName() == null ? "—" : row.getProductName(),
                    row.getAgentName(), row.getStatus(), Money.format(row.getBalance())));
        }
        return rows;
    }
}
