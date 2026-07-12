package com.ogua.susudesktop;

import db.SessionManager;
import java.util.List;
import java.util.Optional;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.fxml.FXML;
import javafx.scene.control.Alert;
import javafx.scene.control.Button;
import javafx.scene.control.ComboBox;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import javafx.scene.control.TextField;
import javafx.scene.control.TextInputDialog;
import models.Customer;
import models.SavingsAccount;
import models.SavingsProduct;
import service.CollectionResult;
import service.CollectionService;
import service.CustomerService;
import service.SavingsAccountService;
import service.SavingsProductService;
import support.Money;

public class SavingsAccountsController {

    @FXML private TableView<SavingsAccount> table;
    @FXML private TableColumn<SavingsAccount, String> accountNumberColumn;
    @FXML private TableColumn<SavingsAccount, String> customerColumn;
    @FXML private TableColumn<SavingsAccount, String> balanceColumn;
    @FXML private TableColumn<SavingsAccount, String> cycleColumn;
    @FXML private TableColumn<SavingsAccount, String> statusColumn;
    @FXML private Label statusLabel;

    @FXML private TextField customerSearchField;
    @FXML private ComboBox<Customer> customerCombo;
    @FXML private TextField contributionField;
    @FXML private Label openStatusLabel;

    private final CustomerService customerService = new CustomerService();
    private final SavingsAccountService accountService = new SavingsAccountService();
    private final SavingsProductService productService = new SavingsProductService();
    private final CollectionService collectionService = new CollectionService();

    @FXML
    private void initialize() {
        accountNumberColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("accountNumber"));
        customerColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getCustomer() != null ? data.getValue().getCustomer().fullName() : ""));
        balanceColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getBalance())));
        cycleColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getContributionsThisCycle() + "/" + data.getValue().getCycleNumber()));
        statusColumn.setCellValueFactory(data -> new SimpleStringProperty(data.getValue().getStatus().value()));

        customerCombo.setConverter(new javafx.util.StringConverter<>() {
            @Override
            public String toString(Customer customer) {
                return customer == null ? "" : customer.fullName() + " (" + customer.getCustomerCode() + ")";
            }

            @Override
            public Customer fromString(String string) {
                return null;
            }
        });

        refresh();
    }

    @FXML
    private void refresh() {
        try {
            List<SavingsAccount> accounts = accountService.findAll();
            table.setItems(FXCollections.observableArrayList(accounts));
        } catch (Exception e) {
            statusLabel.setText("Could not load accounts: " + e.getMessage());
        }
    }

    @FXML
    private void onFindCustomer() {
        try {
            List<Customer> matches = customerService.search(customerSearchField.getText());
            customerCombo.setItems(FXCollections.observableArrayList(matches));
            if (!matches.isEmpty()) {
                customerCombo.getSelectionModel().selectFirst();
            }
        } catch (Exception e) {
            openStatusLabel.setText("Search failed: " + e.getMessage());
        }
    }

    @FXML
    private void onOpenAccount() {
        openStatusLabel.setText("");
        Customer customer = customerCombo.getValue();
        if (customer == null) {
            openStatusLabel.setText("Find and select a customer first.");
            return;
        }

        try {
            SavingsProduct product = productService.getOrCreateDefault();
            Long contributionOverride = null;
            if (!contributionField.getText().isBlank()) {
                contributionOverride = Money.toMinorUnits(contributionField.getText().trim());
            }

            String agentId = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
            accountService.open(customer.getId(), product.getId(), agentId, contributionOverride);

            customerSearchField.clear();
            customerCombo.getItems().clear();
            contributionField.clear();
            refresh();
        } catch (Exception e) {
            openStatusLabel.setText("Could not open account: " + e.getMessage());
        }
    }

    @FXML
    private void onRecordCollection() {
        statusLabel.setText("");
        SavingsAccount selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            statusLabel.setText("Select an account first.");
            return;
        }

        TextInputDialog dialog = new TextInputDialog(Money.format(selected.getContributionAmount())
                .replaceAll("[^0-9.]", ""));
        dialog.setTitle("Record Collection");
        dialog.setHeaderText(selected.getAccountNumber());
        dialog.setContentText("Amount (GHS):");

        Optional<String> input = dialog.showAndWait();
        if (input.isEmpty() || input.get().isBlank()) {
            return;
        }

        try {
            long amount = Money.toMinorUnits(input.get().trim());
            String agentId = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
            String agentName = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getName() : "Agent";

            CollectionResult result = collectionService.record(agentId, agentName, selected.getId(), amount, null, null);

            Alert alert = new Alert(Alert.AlertType.INFORMATION,
                    "Collection recorded. New balance: " + Money.format(result.account().getBalance()));
            alert.setHeaderText(null);
            alert.showAndWait();

            refresh();
        } catch (Exception e) {
            statusLabel.setText("Could not record collection: " + e.getMessage());
        }
    }
}
