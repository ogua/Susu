package com.ogua.susudesktop;

import java.time.LocalDate;
import java.util.ArrayList;
import java.util.List;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.control.ComboBox;
import javafx.scene.control.DatePicker;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import javafx.scene.control.cell.PropertyValueFactory;
import models.WithdrawalRow;
import service.ReportService;
import support.Money;

public class WithdrawalsReportController {

    @FXML private TableView<WithdrawalRow> table;
    @FXML private TableColumn<WithdrawalRow, String> dateColumn;
    @FXML private TableColumn<WithdrawalRow, String> accountColumn;
    @FXML private TableColumn<WithdrawalRow, String> customerColumn;
    @FXML private TableColumn<WithdrawalRow, String> statusColumn;
    @FXML private TableColumn<WithdrawalRow, String> requestedByColumn;
    @FXML private TableColumn<WithdrawalRow, String> approvedByColumn;
    @FXML private TableColumn<WithdrawalRow, String> penaltyColumn;
    @FXML private TableColumn<WithdrawalRow, String> amountColumn;
    @FXML private DatePicker fromDate;
    @FXML private DatePicker untilDate;
    @FXML private ComboBox<String> statusFilter;
    @FXML private Label totalLabel;
    @FXML private Label statusLabel;

    private final ReportService reportService = new ReportService();
    private List<WithdrawalRow> current = List.of();

    @FXML
    private void initialize() {
        dateColumn.setCellValueFactory(new PropertyValueFactory<>("requestedAt"));
        accountColumn.setCellValueFactory(new PropertyValueFactory<>("accountNumber"));
        customerColumn.setCellValueFactory(new PropertyValueFactory<>("customerName"));
        statusColumn.setCellValueFactory(new PropertyValueFactory<>("status"));
        requestedByColumn.setCellValueFactory(new PropertyValueFactory<>("requestedBy"));
        approvedByColumn.setCellValueFactory(new PropertyValueFactory<>("approvedBy"));
        penaltyColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getPenalty())));
        amountColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getAmount())));

        statusFilter.setItems(FXCollections.observableArrayList("All", "pending", "approved", "rejected", "paid"));
        statusFilter.getSelectionModel().selectFirst();

        refresh();
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading…");
        LocalDate from = fromDate.getValue();
        LocalDate until = untilDate.getValue();
        String status = statusFilter.getValue();
        String statusParam = status == null || "All".equals(status) ? null : status;

        Task<List<WithdrawalRow>> task = new Task<>() {
            @Override
            protected List<WithdrawalRow> call() throws Exception {
                return reportService.withdrawals(from, until, statusParam);
            }
        };
        task.setOnSucceeded(event -> {
            current = task.getValue();
            table.setItems(FXCollections.observableArrayList(current));
            long total = current.stream().mapToLong(WithdrawalRow::getAmount).sum();
            totalLabel.setText(current.size() + " requests · " + Money.format(total));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load withdrawals: " + task.getException().getMessage()));
        new Thread(task, "withdrawals-report-refresh").start();
    }

    @FXML
    private void exportPdf() {
        ReportExporter.exportPdf(table.getScene().getWindow(), "withdrawals-report",
                "Withdrawals Report",
                List.of("Period: " + fromDate.getValue() + " – " + untilDate.getValue()),
                headers(), rows(), statusLabel);
    }

    @FXML
    private void exportCsv() {
        ReportExporter.exportCsv(table.getScene().getWindow(), "withdrawals-report",
                headers(), rows(), statusLabel);
    }

    private List<String> headers() {
        return List.of("Requested", "Account", "Customer", "Status", "Requested By", "Approved By", "Penalty", "Amount");
    }

    private List<List<String>> rows() {
        List<List<String>> rows = new ArrayList<>();
        for (WithdrawalRow row : current) {
            rows.add(List.of(row.getRequestedAt(), row.getAccountNumber() == null ? "—" : row.getAccountNumber(),
                    row.getCustomerName(), row.getStatus(), row.getRequestedBy(), row.getApprovedBy(),
                    Money.format(row.getPenalty()), Money.format(row.getAmount())));
        }
        return rows;
    }
}
