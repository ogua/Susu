package com.ogua.susudesktop;

import java.time.LocalDate;
import java.util.ArrayList;
import java.util.List;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.control.DatePicker;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import javafx.scene.control.cell.PropertyValueFactory;
import models.AgentPerformanceRow;
import service.ReportService;
import support.Money;

public class AgentPerformanceController {

    @FXML private TableView<AgentPerformanceRow> table;
    @FXML private TableColumn<AgentPerformanceRow, String> agentColumn;
    @FXML private TableColumn<AgentPerformanceRow, Number> daysColumn;
    @FXML private TableColumn<AgentPerformanceRow, Number> countColumn;
    @FXML private TableColumn<AgentPerformanceRow, String> totalColumn;
    @FXML private TableColumn<AgentPerformanceRow, String> varianceColumn;
    @FXML private TableColumn<AgentPerformanceRow, Number> unreconciledColumn;
    @FXML private TableColumn<AgentPerformanceRow, String> commissionColumn;
    @FXML private DatePicker fromDate;
    @FXML private DatePicker untilDate;
    @FXML private Label totalLabel;
    @FXML private Label statusLabel;

    private final ReportService reportService = new ReportService();
    private List<AgentPerformanceRow> current = List.of();

    @FXML
    private void initialize() {
        agentColumn.setCellValueFactory(new PropertyValueFactory<>("agentName"));
        daysColumn.setCellValueFactory(new PropertyValueFactory<>("daysWorked"));
        countColumn.setCellValueFactory(new PropertyValueFactory<>("collectionsCount"));
        totalColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getCollectionsTotal())));
        varianceColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getVarianceTotal())));
        unreconciledColumn.setCellValueFactory(new PropertyValueFactory<>("unreconciledDays"));
        commissionColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getCommissionTotal())));

        fromDate.setValue(LocalDate.now().withDayOfMonth(1));
        untilDate.setValue(LocalDate.now());

        refresh();
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading…");
        LocalDate from = fromDate.getValue();
        LocalDate until = untilDate.getValue();

        Task<List<AgentPerformanceRow>> task = new Task<>() {
            @Override
            protected List<AgentPerformanceRow> call() throws Exception {
                return reportService.agentPerformance(from, until);
            }
        };
        task.setOnSucceeded(event -> {
            current = task.getValue();
            table.setItems(FXCollections.observableArrayList(current));
            long collections = current.stream().mapToLong(AgentPerformanceRow::getCollectionsTotal).sum();
            long commission = current.stream().mapToLong(AgentPerformanceRow::getCommissionTotal).sum();
            totalLabel.setText("Collections " + Money.format(collections) + " · Commission " + Money.format(commission));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load agent performance: " + task.getException().getMessage()));
        new Thread(task, "agent-performance-refresh").start();
    }

    @FXML
    private void exportPdf() {
        ReportExporter.exportPdf(table.getScene().getWindow(), "agent-performance",
                "Agent Performance & Commissions",
                List.of("Period: " + fromDate.getValue() + " – " + untilDate.getValue()),
                headers(), rows(), statusLabel);
    }

    @FXML
    private void exportCsv() {
        ReportExporter.exportCsv(table.getScene().getWindow(), "agent-performance",
                headers(), rows(), statusLabel);
    }

    private List<String> headers() {
        return List.of("Agent", "Days Worked", "Collections", "Collections Total", "Variance", "Unreconciled Days", "Commission");
    }

    private List<List<String>> rows() {
        List<List<String>> rows = new ArrayList<>();
        for (AgentPerformanceRow row : current) {
            rows.add(List.of(row.getAgentName(), String.valueOf(row.getDaysWorked()),
                    String.valueOf(row.getCollectionsCount()), Money.format(row.getCollectionsTotal()),
                    Money.format(row.getVarianceTotal()), String.valueOf(row.getUnreconciledDays()),
                    Money.format(row.getCommissionTotal())));
        }
        return rows;
    }
}
