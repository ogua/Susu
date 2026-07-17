package com.ogua.susudesktop;

import enums.LedgerAccountType;
import java.io.IOException;
import java.util.List;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.fxml.FXMLLoader;
import javafx.scene.Parent;
import javafx.scene.Scene;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import javafx.scene.control.cell.PropertyValueFactory;
import javafx.stage.Modality;
import javafx.stage.Stage;
import javafx.stage.Window;
import models.Customer;
import models.LedgerAccount;
import models.LedgerEntryRow;
import models.SavingsAccount;
import service.ReportService;
import service.SavingsAccountService;
import support.Money;

/**
 * Customer 360 view — profile, savings accounts, and per-account transaction
 * history (the desktop mirror of the web admin's customer page with its
 * accounts/entries relation managers). Opened as a modal from the Customers
 * screen.
 */
public class CustomerDetailController {

    @FXML private Label nameLabel;
    @FXML private Label codeLabel;
    @FXML private Label phoneLabel;
    @FXML private Label statusValueLabel;
    @FXML private Label kinLabel;
    @FXML private Label addressLabel;

    @FXML private TableView<SavingsAccount> accountsTable;
    @FXML private TableColumn<SavingsAccount, String> accountNumberColumn;
    @FXML private TableColumn<SavingsAccount, String> accountStatusColumn;
    @FXML private TableColumn<SavingsAccount, String> accountOpenedColumn;
    @FXML private TableColumn<SavingsAccount, String> accountBalanceColumn;

    @FXML private Label transactionsTitleLabel;
    @FXML private TableView<LedgerEntryRow> transactionsTable;
    @FXML private TableColumn<LedgerEntryRow, String> txnDateColumn;
    @FXML private TableColumn<LedgerEntryRow, String> txnTypeColumn;
    @FXML private TableColumn<LedgerEntryRow, String> txnDescriptionColumn;
    @FXML private TableColumn<LedgerEntryRow, String> txnDebitColumn;
    @FXML private TableColumn<LedgerEntryRow, String> txnCreditColumn;
    @FXML private TableColumn<LedgerEntryRow, String> txnBalanceColumn;
    @FXML private Label statusLabel;

    private final SavingsAccountService accountService = new SavingsAccountService();
    private final ReportService reportService = new ReportService();

    public static void show(Window owner, Customer customer) {
        try {
            FXMLLoader loader = new FXMLLoader(
                    CustomerDetailController.class.getResource("customer-detail-view.fxml"));
            Parent view = loader.load();
            CustomerDetailController controller = loader.getController();
            controller.load(customer);

            Stage stage = new Stage();
            stage.setTitle("Customer — " + customer.fullName());
            stage.initModality(Modality.WINDOW_MODAL);
            stage.initOwner(owner);
            Scene scene = new Scene(view, 860, 620);
            scene.getStylesheets().addAll(owner.getScene().getStylesheets());
            stage.setScene(scene);
            stage.show();
        } catch (IOException e) {
            throw new IllegalStateException("Could not open customer detail: " + e.getMessage(), e);
        }
    }

    @FXML
    private void initialize() {
        accountNumberColumn.setCellValueFactory(new PropertyValueFactory<>("accountNumber"));
        accountStatusColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getStatus().value()));
        accountOpenedColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getOpenedAt() != null && data.getValue().getOpenedAt().length() >= 10
                        ? data.getValue().getOpenedAt().substring(0, 10)
                        : "—"));
        accountBalanceColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getBalance())));

        txnDateColumn.setCellValueFactory(new PropertyValueFactory<>("recordedAt"));
        txnTypeColumn.setCellValueFactory(new PropertyValueFactory<>("type"));
        txnDescriptionColumn.setCellValueFactory(new PropertyValueFactory<>("description"));
        txnDebitColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getDebit() > 0 ? Money.format(data.getValue().getDebit()) : ""));
        txnCreditColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getCredit() > 0 ? Money.format(data.getValue().getCredit()) : ""));
        txnBalanceColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getRunningBalance())));

        accountsTable.getSelectionModel().selectedItemProperty()
                .addListener((obs, old, selected) -> loadTransactions(selected));
    }

    private void load(Customer customer) {
        nameLabel.setText(customer.fullName());
        codeLabel.setText(customer.getCustomerCode());
        phoneLabel.setText(customer.getPhone());
        statusValueLabel.setText(customer.getStatus().value());
        kinLabel.setText(customer.getNextOfKinName() != null
                ? customer.getNextOfKinName()
                + (customer.getNextOfKinPhone() != null ? " (" + customer.getNextOfKinPhone() + ")" : "")
                : "—");
        addressLabel.setText(customer.getAddress() != null ? customer.getAddress() : "—");

        Task<List<SavingsAccount>> task = new Task<>() {
            @Override
            protected List<SavingsAccount> call() throws Exception {
                return accountService.findByCustomer(customer.getId());
            }
        };
        task.setOnSucceeded(event -> {
            accountsTable.setItems(FXCollections.observableArrayList(task.getValue()));
            if (!task.getValue().isEmpty()) {
                accountsTable.getSelectionModel().selectFirst();
            }
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load accounts: " + task.getException().getMessage()));
        new Thread(task, "customer-detail-accounts").start();
    }

    private void loadTransactions(SavingsAccount account) {
        if (account == null || account.getLedgerAccountId() == null) {
            transactionsTitleLabel.setText("Transactions");
            transactionsTable.setItems(FXCollections.observableArrayList());
            return;
        }

        // The account's savings-liability ledger account is what its
        // deposits/withdrawals post against; credit-normal running balance
        // matches the customer's passbook view.
        LedgerAccount ledgerAccount = new LedgerAccount();
        ledgerAccount.setId(account.getLedgerAccountId());
        ledgerAccount.setType(LedgerAccountType.fromValue("liability"));

        Task<service.AccountLedgerResult> task = new Task<>() {
            @Override
            protected service.AccountLedgerResult call() throws Exception {
                return reportService.accountLedger(ledgerAccount, null, null);
            }
        };
        task.setOnSucceeded(event -> {
            transactionsTable.setItems(FXCollections.observableArrayList(task.getValue().getRows()));
            transactionsTitleLabel.setText("Transactions — " + account.getAccountNumber());
            statusLabel.setText(task.getValue().getRows().isEmpty() ? "No transactions yet." : "");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load transactions: " + task.getException().getMessage()));
        new Thread(task, "customer-detail-transactions").start();
    }
}
