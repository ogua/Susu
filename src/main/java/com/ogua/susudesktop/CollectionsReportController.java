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
import models.CollectionsRow;
import service.ReportService;
import support.Money;

public class CollectionsReportController {

    @FXML private TableView<CollectionsRow> table;
    @FXML private TableColumn<CollectionsRow, String> dateColumn;
    @FXML private TableColumn<CollectionsRow, String> referenceColumn;
    @FXML private TableColumn<CollectionsRow, String> agentColumn;
    @FXML private TableColumn<CollectionsRow, String> descriptionColumn;
    @FXML private TableColumn<CollectionsRow, String> methodColumn;
    @FXML private TableColumn<CollectionsRow, String> statusColumn;
    @FXML private TableColumn<CollectionsRow, String> amountColumn;
    @FXML private DatePicker fromDate;
    @FXML private DatePicker untilDate;
    @FXML private Label totalLabel;
    @FXML private Label statusLabel;

    private final ReportService reportService = new ReportService();
    private List<CollectionsRow> current = List.of();

    @FXML
    private void initialize() {
        dateColumn.setCellValueFactory(new PropertyValueFactory<>("recordedAt"));
        referenceColumn.setCellValueFactory(new PropertyValueFactory<>("reference"));
        agentColumn.setCellValueFactory(new PropertyValueFactory<>("agentName"));
        descriptionColumn.setCellValueFactory(new PropertyValueFactory<>("description"));
        methodColumn.setCellValueFactory(new PropertyValueFactory<>("paymentMethod"));
        statusColumn.setCellValueFactory(new PropertyValueFactory<>("status"));
        amountColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getAmount())));

        fromDate.setValue(LocalDate.now().withDayOfMonth(1));
        untilDate.setValue(LocalDate.now());

        refresh();
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading…");
        Task<List<CollectionsRow>> task = new Task<>() {
            @Override
            protected List<CollectionsRow> call() throws Exception {
                return reportService.collections(fromDate.getValue(), untilDate.getValue());
            }
        };
        task.setOnSucceeded(event -> {
            current = task.getValue();
            table.setItems(FXCollections.observableArrayList(current));
            long total = current.stream().mapToLong(CollectionsRow::getAmount).sum();
            totalLabel.setText("Total: " + Money.format(total) + " (" + current.size() + " collections)");
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load collections: " + task.getException().getMessage()));
        new Thread(task, "collections-report-refresh").start();
    }

    @FXML
    private void exportPdf() {
        ReportExporter.exportPdf(table.getScene().getWindow(), "collections-report",
                "Collections Report", metaLines(), headers(), rows(), statusLabel);
    }

    @FXML
    private void exportCsv() {
        ReportExporter.exportCsv(table.getScene().getWindow(), "collections-report",
                headers(), rows(), statusLabel);
    }

    private List<String> metaLines() {
        return List.of("Period: " + fromDate.getValue() + " – " + untilDate.getValue());
    }

    private List<String> headers() {
        return List.of("Date", "Reference", "Agent", "Description", "Method", "Status", "Amount");
    }

    private List<List<String>> rows() {
        List<List<String>> rows = new ArrayList<>();
        for (CollectionsRow row : current) {
            rows.add(List.of(row.getRecordedAt(), row.getReference(), row.getAgentName(),
                    row.getDescription() == null ? "" : row.getDescription(),
                    row.getPaymentMethod(), row.getStatus(), Money.format(row.getAmount())));
        }
        return rows;
    }
}
