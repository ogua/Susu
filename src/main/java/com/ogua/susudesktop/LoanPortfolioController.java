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
import models.LoanPortfolioRow;
import service.ReportService;
import support.Money;

public class LoanPortfolioController {

    @FXML private TableView<LoanPortfolioRow> table;
    @FXML private TableColumn<LoanPortfolioRow, String> loanNumberColumn;
    @FXML private TableColumn<LoanPortfolioRow, String> customerColumn;
    @FXML private TableColumn<LoanPortfolioRow, String> agentColumn;
    @FXML private TableColumn<LoanPortfolioRow, String> statusColumn;
    @FXML private TableColumn<LoanPortfolioRow, String> appliedColumn;
    @FXML private TableColumn<LoanPortfolioRow, String> disbursedColumn;
    @FXML private TableColumn<LoanPortfolioRow, String> principalColumn;
    @FXML private TableColumn<LoanPortfolioRow, String> outstandingColumn;
    @FXML private DatePicker fromDate;
    @FXML private DatePicker untilDate;
    @FXML private ComboBox<String> statusFilter;
    @FXML private Label totalLabel;
    @FXML private Label statusLabel;

    private final ReportService reportService = new ReportService();
    private List<LoanPortfolioRow> current = List.of();

    @FXML
    private void initialize() {
        loanNumberColumn.setCellValueFactory(new PropertyValueFactory<>("loanNumber"));
        customerColumn.setCellValueFactory(new PropertyValueFactory<>("customerName"));
        agentColumn.setCellValueFactory(new PropertyValueFactory<>("agentName"));
        statusColumn.setCellValueFactory(new PropertyValueFactory<>("status"));
        appliedColumn.setCellValueFactory(new PropertyValueFactory<>("appliedAt"));
        disbursedColumn.setCellValueFactory(new PropertyValueFactory<>("disbursedAt"));
        principalColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getPrincipal())));
        outstandingColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getOutstanding())));

        statusFilter.setItems(FXCollections.observableArrayList(
                "All", "applied", "approved", "rejected", "disbursed", "closed", "written_off"));
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

        Task<List<LoanPortfolioRow>> task = new Task<>() {
            @Override
            protected List<LoanPortfolioRow> call() throws Exception {
                return reportService.loanPortfolio(from, until, statusParam);
            }
        };
        task.setOnSucceeded(event -> {
            current = task.getValue();
            table.setItems(FXCollections.observableArrayList(current));
            long principal = current.stream().mapToLong(LoanPortfolioRow::getPrincipal).sum();
            long outstanding = current.stream().mapToLong(LoanPortfolioRow::getOutstanding).sum();
            totalLabel.setText(current.size() + " loans · Principal " + Money.format(principal)
                    + " · Outstanding " + Money.format(outstanding));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load loan portfolio: " + task.getException().getMessage()));
        new Thread(task, "loan-portfolio-refresh").start();
    }

    @FXML
    private void exportPdf() {
        ReportExporter.exportPdf(table.getScene().getWindow(), "loan-portfolio",
                "Loan Portfolio Report",
                List.of("Period (applied): " + fromDate.getValue() + " – " + untilDate.getValue()),
                headers(), rows(), statusLabel);
    }

    @FXML
    private void exportCsv() {
        ReportExporter.exportCsv(table.getScene().getWindow(), "loan-portfolio",
                headers(), rows(), statusLabel);
    }

    private List<String> headers() {
        return List.of("Loan #", "Customer", "Agent", "Status", "Applied", "Disbursed", "Principal", "Outstanding");
    }

    private List<List<String>> rows() {
        List<List<String>> rows = new ArrayList<>();
        for (LoanPortfolioRow row : current) {
            rows.add(List.of(row.getLoanNumber(), row.getCustomerName(), row.getAgentName(), row.getStatus(),
                    row.getAppliedAt(), row.getDisbursedAt(),
                    Money.format(row.getPrincipal()), Money.format(row.getOutstanding())));
        }
        return rows;
    }
}
