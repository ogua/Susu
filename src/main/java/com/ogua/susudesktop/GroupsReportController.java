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
import models.GroupReportRow;
import service.ReportService;
import support.Money;

public class GroupsReportController {

    @FXML private TableView<GroupReportRow> table;
    @FXML private TableColumn<GroupReportRow, String> nameColumn;
    @FXML private TableColumn<GroupReportRow, String> codeColumn;
    @FXML private TableColumn<GroupReportRow, String> statusColumn;
    @FXML private TableColumn<GroupReportRow, Number> membersColumn;
    @FXML private TableColumn<GroupReportRow, String> roundColumn;
    @FXML private TableColumn<GroupReportRow, String> roundProgressColumn;
    @FXML private TableColumn<GroupReportRow, Number> paidOutColumn;
    @FXML private TableColumn<GroupReportRow, String> lifetimeColumn;
    @FXML private Label totalLabel;
    @FXML private Label statusLabel;

    private final ReportService reportService = new ReportService();
    private List<GroupReportRow> current = List.of();

    @FXML
    private void initialize() {
        nameColumn.setCellValueFactory(new PropertyValueFactory<>("name"));
        codeColumn.setCellValueFactory(new PropertyValueFactory<>("code"));
        statusColumn.setCellValueFactory(new PropertyValueFactory<>("status"));
        membersColumn.setCellValueFactory(new PropertyValueFactory<>("membersCount"));
        roundColumn.setCellValueFactory(new PropertyValueFactory<>("currentRound"));
        roundProgressColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getRoundCollected()) + " / " + Money.format(data.getValue().getRoundExpected())));
        paidOutColumn.setCellValueFactory(new PropertyValueFactory<>("roundsPaidOut"));
        lifetimeColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getLifetimeCollected())));

        refresh();
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading…");
        Task<List<GroupReportRow>> task = new Task<>() {
            @Override
            protected List<GroupReportRow> call() throws Exception {
                return reportService.groupsReport();
            }
        };
        task.setOnSucceeded(event -> {
            current = task.getValue();
            table.setItems(FXCollections.observableArrayList(current));
            long lifetime = current.stream().mapToLong(GroupReportRow::getLifetimeCollected).sum();
            totalLabel.setText(current.size() + " groups · Lifetime collected " + Money.format(lifetime));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load groups: " + task.getException().getMessage()));
        new Thread(task, "groups-report-refresh").start();
    }

    @FXML
    private void exportPdf() {
        ReportExporter.exportPdf(table.getScene().getWindow(), "groups-report",
                "Susu Groups Report", List.of(), headers(), rows(), statusLabel);
    }

    @FXML
    private void exportCsv() {
        ReportExporter.exportCsv(table.getScene().getWindow(), "groups-report",
                headers(), rows(), statusLabel);
    }

    private List<String> headers() {
        return List.of("Group", "Code", "Status", "Members", "Round", "Round Collected", "Round Expected", "Rounds Paid Out", "Lifetime Collected");
    }

    private List<List<String>> rows() {
        List<List<String>> rows = new ArrayList<>();
        for (GroupReportRow row : current) {
            rows.add(List.of(row.getName(), row.getCode(), row.getStatus(),
                    String.valueOf(row.getMembersCount()), row.getCurrentRound(),
                    Money.format(row.getRoundCollected()), Money.format(row.getRoundExpected()),
                    String.valueOf(row.getRoundsPaidOut()), Money.format(row.getLifetimeCollected())));
        }
        return rows;
    }
}
