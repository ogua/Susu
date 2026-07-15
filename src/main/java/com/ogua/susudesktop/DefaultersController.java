package com.ogua.susudesktop;

import java.util.List;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import models.DefaulterRow;
import service.ReportService;
import support.Money;

public class DefaultersController {

    @FXML private TableView<DefaulterRow> table;
    @FXML private TableColumn<DefaulterRow, String> loanNumberColumn;
    @FXML private TableColumn<DefaulterRow, String> customerColumn;
    @FXML private TableColumn<DefaulterRow, String> phoneColumn;
    @FXML private TableColumn<DefaulterRow, String> agentColumn;
    @FXML private TableColumn<DefaulterRow, String> daysOverdueColumn;
    @FXML private TableColumn<DefaulterRow, String> remainingColumn;
    @FXML private Label statusLabel;

    private final ReportService reportService = new ReportService();

    @FXML
    private void initialize() {
        loanNumberColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("loanNumber"));
        customerColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("customerName"));
        phoneColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("customerPhone"));
        agentColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("agentName"));
        daysOverdueColumn.setCellValueFactory(data -> new SimpleStringProperty(
                String.valueOf(data.getValue().getDaysOverdue())));
        remainingColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getRemaining())));

        refresh();
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading…");
        Task<List<DefaulterRow>> task = new Task<>() {
            @Override
            protected List<DefaulterRow> call() throws Exception {
                return reportService.defaulters();
            }
        };
        task.setOnSucceeded(event -> {
            table.setItems(FXCollections.observableArrayList(task.getValue()));
            statusLabel.setText(task.getValue().isEmpty()
                    ? "No defaulters — every installment is current." : "");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load defaulters: " + task.getException().getMessage()));
        new Thread(task, "defaulters-refresh").start();
    }
}
