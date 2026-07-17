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
import models.LedgerAccount;
import models.LedgerEntryRow;
import service.AccountLedgerResult;
import service.ReportService;
import support.Money;

/**
 * Chart of Accounts with a per-account ledger drill-down — the desktop
 * mirror of the web admin's LedgerAccountResource + AccountLedger page:
 * select an account to see its posted entries with opening/running balances,
 * filter by date, and export the ledger as PDF/CSV.
 */
public class ChartOfAccountsController {

    @FXML private TableView<LedgerAccount> accountsTable;
    @FXML private TableColumn<LedgerAccount, String> codeColumn;
    @FXML private TableColumn<LedgerAccount, String> nameColumn;
    @FXML private TableColumn<LedgerAccount, String> typeColumn;
    @FXML private TableColumn<LedgerAccount, String> balanceColumn;

    @FXML private Label ledgerTitleLabel;
    @FXML private Label openingBalanceLabel;
    @FXML private Label closingBalanceLabel;
    @FXML private DatePicker fromDate;
    @FXML private DatePicker untilDate;
    @FXML private TableView<LedgerEntryRow> ledgerTable;
    @FXML private TableColumn<LedgerEntryRow, String> entryDateColumn;
    @FXML private TableColumn<LedgerEntryRow, String> entryReferenceColumn;
    @FXML private TableColumn<LedgerEntryRow, String> entryTypeColumn;
    @FXML private TableColumn<LedgerEntryRow, String> entryDescriptionColumn;
    @FXML private TableColumn<LedgerEntryRow, String> entryDebitColumn;
    @FXML private TableColumn<LedgerEntryRow, String> entryCreditColumn;
    @FXML private TableColumn<LedgerEntryRow, String> entryBalanceColumn;
    @FXML private Label statusLabel;

    private final ReportService reportService = new ReportService();
    private LedgerAccount selectedAccount;
    private List<LedgerEntryRow> currentRows = List.of();

    @FXML
    private void initialize() {
        codeColumn.setCellValueFactory(new PropertyValueFactory<>("code"));
        nameColumn.setCellValueFactory(new PropertyValueFactory<>("name"));
        typeColumn.setCellValueFactory(data -> new SimpleStringProperty(data.getValue().getType().value()));
        balanceColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getBalance())));

        entryDateColumn.setCellValueFactory(new PropertyValueFactory<>("recordedAt"));
        entryReferenceColumn.setCellValueFactory(new PropertyValueFactory<>("reference"));
        entryTypeColumn.setCellValueFactory(new PropertyValueFactory<>("type"));
        entryDescriptionColumn.setCellValueFactory(new PropertyValueFactory<>("description"));
        entryDebitColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getDebit() > 0 ? Money.format(data.getValue().getDebit()) : ""));
        entryCreditColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getCredit() > 0 ? Money.format(data.getValue().getCredit()) : ""));
        entryBalanceColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getRunningBalance())));

        accountsTable.getSelectionModel().selectedItemProperty().addListener((obs, old, selected) -> {
            selectedAccount = selected;
            loadLedger();
        });

        refreshAccounts();
    }

    @FXML
    private void refreshAccounts() {
        statusLabel.setText("Loading…");
        Task<List<LedgerAccount>> task = new Task<>() {
            @Override
            protected List<LedgerAccount> call() throws Exception {
                return reportService.trialBalance();
            }
        };
        task.setOnSucceeded(event -> {
            accountsTable.setItems(FXCollections.observableArrayList(task.getValue()));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load accounts: " + task.getException().getMessage()));
        new Thread(task, "chart-of-accounts-refresh").start();
    }

    @FXML
    private void loadLedger() {
        if (selectedAccount == null) {
            ledgerTitleLabel.setText("Select an account to view its ledger");
            ledgerTable.setItems(FXCollections.observableArrayList());
            openingBalanceLabel.setText("");
            closingBalanceLabel.setText("");
            return;
        }

        LedgerAccount account = selectedAccount;
        LocalDate from = fromDate.getValue();
        LocalDate until = untilDate.getValue();

        statusLabel.setText("Loading ledger…");
        Task<AccountLedgerResult> task = new Task<>() {
            @Override
            protected AccountLedgerResult call() throws Exception {
                return reportService.accountLedger(account, from, until);
            }
        };
        task.setOnSucceeded(event -> {
            AccountLedgerResult result = task.getValue();
            currentRows = result.getRows();
            ledgerTable.setItems(FXCollections.observableArrayList(currentRows));
            ledgerTitleLabel.setText(account.getCode() + " — " + account.getName());
            openingBalanceLabel.setText(from != null
                    ? "Opening: " + Money.format(result.getOpeningBalance()) : "");
            closingBalanceLabel.setText("Closing: " + Money.format(result.getClosingBalance()));
            statusLabel.setText(currentRows.isEmpty() ? "No transactions in this period." : "");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load ledger: " + task.getException().getMessage()));
        new Thread(task, "account-ledger-refresh").start();
    }

    @FXML
    private void exportPdf() {
        if (selectedAccount == null) {
            statusLabel.setText("Select an account first.");
            return;
        }
        ReportExporter.exportPdf(ledgerTable.getScene().getWindow(), "account-ledger",
                "Account Ledger — " + selectedAccount.getCode() + " " + selectedAccount.getName(),
                metaLines(), headers(), rows(), statusLabel);
    }

    @FXML
    private void exportCsv() {
        if (selectedAccount == null) {
            statusLabel.setText("Select an account first.");
            return;
        }
        ReportExporter.exportCsv(ledgerTable.getScene().getWindow(), "account-ledger",
                headers(), rows(), statusLabel);
    }

    private List<String> metaLines() {
        return List.of("Period: " + (fromDate.getValue() != null ? fromDate.getValue() : "Beginning")
                + " – " + (untilDate.getValue() != null ? untilDate.getValue() : "Today"));
    }

    private List<String> headers() {
        return List.of("Date", "Reference", "Type", "Description", "Debit", "Credit", "Balance");
    }

    private List<List<String>> rows() {
        List<List<String>> rows = new ArrayList<>();
        for (LedgerEntryRow row : currentRows) {
            rows.add(List.of(row.getRecordedAt(), row.getReference(), row.getType(),
                    row.getDescription() == null ? "" : row.getDescription(),
                    row.getDebit() > 0 ? Money.format(row.getDebit()) : "",
                    row.getCredit() > 0 ? Money.format(row.getCredit()) : "",
                    Money.format(row.getRunningBalance())));
        }
        return rows;
    }
}
