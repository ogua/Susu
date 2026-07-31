package com.ogua.susudesktop;

import db.SessionManager;
import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.util.List;
import java.util.Optional;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.control.Alert;
import javafx.scene.control.Button;
import javafx.scene.control.ComboBox;
import javafx.scene.control.DatePicker;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import javafx.scene.control.TextField;
import javafx.scene.control.TextInputDialog;
import javafx.scene.layout.VBox;
import models.Customer;
import models.SavingsAccount;
import models.SavingsProduct;
import service.CollectionResult;
import service.CollectionService;
import service.CustomerService;
import service.SavingsAccountService;
import service.SavingsProductService;
import service.SharesService;
import support.Money;

public class SavingsAccountsController {

    @FXML private TableView<SavingsAccount> table;
    @FXML private TableColumn<SavingsAccount, String> accountNumberColumn;
    @FXML private TableColumn<SavingsAccount, String> customerColumn;
    @FXML private TableColumn<SavingsAccount, String> balanceColumn;
    @FXML private TableColumn<SavingsAccount, String> cycleColumn;
    @FXML private TableColumn<SavingsAccount, String> targetColumn;
    @FXML private TableColumn<SavingsAccount, String> statusColumn;
    @FXML private Label statusLabel;

    @FXML private TextField customerSearchField;
    @FXML private ComboBox<Customer> customerCombo;
    @FXML private ComboBox<SavingsProduct> productCombo;
    @FXML private Label contributionLabel;
    @FXML private TextField contributionField;
    @FXML private VBox targetFieldsBox;
    @FXML private TextField targetAmountField;
    @FXML private DatePicker maturesAtPicker;
    @FXML private VBox fdFieldsBox;
    @FXML private DatePicker fdMaturesAtPicker;
    @FXML private Label openStatusLabel;

    private final CustomerService customerService = new CustomerService();
    private final SavingsAccountService accountService = new SavingsAccountService();
    private final SavingsProductService productService = new SavingsProductService();
    private final CollectionService collectionService = new CollectionService();
    private final SharesService sharesService = new SharesService();

    @FXML
    private void initialize() {
        accountNumberColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("accountNumber"));
        customerColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getCustomer() != null ? data.getValue().getCustomer().fullName() : ""));
        balanceColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getBalance())));
        cycleColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getContributionsThisCycle() + "/" + data.getValue().getCycleNumber()));
        targetColumn.setCellValueFactory(data -> {
            Double progress = data.getValue().targetProgressPercent();
            return new SimpleStringProperty(progress == null
                    ? "" : progress + "% of " + Money.format(data.getValue().getTargetAmount()));
        });
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

        productCombo.setConverter(new javafx.util.StringConverter<>() {
            @Override
            public String toString(SavingsProduct product) {
                return product == null ? "" : product.getName();
            }

            @Override
            public SavingsProduct fromString(String string) {
                return null;
            }
        });
        productCombo.valueProperty().addListener((obs, old, selected) -> {
            boolean isTarget = selected != null && selected.isTarget();
            boolean isFixedDeposit = selected != null && selected.isFixedDeposit();
            targetFieldsBox.setVisible(isTarget);
            targetFieldsBox.setManaged(isTarget);
            fdFieldsBox.setVisible(isFixedDeposit);
            fdFieldsBox.setManaged(isFixedDeposit);
            contributionLabel.setText(isFixedDeposit ? "Principal amount (GHS)" : "Daily contribution (GHS)");
        });
        loadProducts();

        refresh();
    }

    private void loadProducts() {
        Task<List<SavingsProduct>> task = new Task<>() {
            @Override
            protected List<SavingsProduct> call() throws Exception {
                SavingsProduct dailySusu = productService.getOrCreateDefault();
                SavingsProduct target = productService.getOrCreateDefaultTarget();
                SavingsProduct fixedDeposit = productService.getOrCreateDefaultFixedDeposit();
                SavingsProduct shares = productService.getOrCreateDefaultShares();
                return List.of(dailySusu, target, fixedDeposit, shares);
            }
        };
        task.setOnSucceeded(event -> {
            productCombo.setItems(FXCollections.observableArrayList(task.getValue()));
            productCombo.getSelectionModel().selectFirst();
        });
        new Thread(task, "savings-products-load").start();
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading accounts…");
        Task<List<SavingsAccount>> task = new Task<>() {
            @Override
            protected List<SavingsAccount> call() throws Exception {
                return accountService.findAll();
            }
        };
        task.setOnSucceeded(event -> {
            table.setItems(FXCollections.observableArrayList(task.getValue()));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load accounts: " + task.getException().getMessage()));
        new Thread(task, "accounts-refresh").start();
    }

    @FXML
    private void onFindCustomer() {
        openStatusLabel.setText("Searching…");
        String query = customerSearchField.getText();
        Task<List<Customer>> task = new Task<>() {
            @Override
            protected List<Customer> call() throws Exception {
                return customerService.search(query);
            }
        };
        task.setOnSucceeded(event -> {
            List<Customer> matches = task.getValue();
            customerCombo.setItems(FXCollections.observableArrayList(matches));
            if (!matches.isEmpty()) {
                customerCombo.getSelectionModel().selectFirst();
            }
            openStatusLabel.setText("");
        });
        task.setOnFailed(event -> openStatusLabel.setText(
                "Search failed: " + task.getException().getMessage()));
        new Thread(task, "customer-search").start();
    }

    @FXML
    private void onOpenAccount() {
        openStatusLabel.setText("");
        Customer customer = customerCombo.getValue();
        if (customer == null) {
            openStatusLabel.setText("Find and select a customer first.");
            return;
        }
        SavingsProduct product = productCombo.getValue();
        if (product == null) {
            openStatusLabel.setText("Select a product first.");
            return;
        }

        Long contributionOverride;
        try {
            contributionOverride = contributionField.getText().isBlank()
                    ? null : Money.toMinorUnits(contributionField.getText().trim());
        } catch (Exception e) {
            openStatusLabel.setText("Invalid contribution amount.");
            return;
        }

        Long targetAmount = null;
        String maturesAt = null;
        if (product.isTarget()) {
            try {
                targetAmount = targetAmountField.getText().isBlank()
                        ? null : Money.toMinorUnits(targetAmountField.getText().trim());
            } catch (Exception e) {
                openStatusLabel.setText("Invalid target amount.");
                return;
            }
            LocalDate maturesAtDate = maturesAtPicker.getValue();
            if (targetAmount == null || maturesAtDate == null) {
                openStatusLabel.setText("Target accounts require a target amount and maturity date.");
                return;
            }
            maturesAt = maturesAtDate.format(DateTimeFormatter.ISO_LOCAL_DATE);
        } else if (product.isFixedDeposit()) {
            LocalDate maturesAtDate = fdMaturesAtPicker.getValue();
            if (maturesAtDate == null) {
                openStatusLabel.setText("Fixed deposit accounts require a maturity date.");
                return;
            }
            maturesAt = maturesAtDate.format(DateTimeFormatter.ISO_LOCAL_DATE);
        }

        String agentId = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        openStatusLabel.setText("Opening account…");

        Long finalTargetAmount = targetAmount;
        String finalMaturesAt = maturesAt;
        Task<Void> task = new Task<>() {
            @Override
            protected Void call() throws Exception {
                accountService.open(customer.getId(), product.getId(), agentId, contributionOverride,
                        finalTargetAmount, finalMaturesAt);
                return null;
            }
        };
        task.setOnSucceeded(event -> {
            customerSearchField.clear();
            customerCombo.getItems().clear();
            contributionField.clear();
            targetAmountField.clear();
            maturesAtPicker.setValue(null);
            fdMaturesAtPicker.setValue(null);
            openStatusLabel.setText("");
            refresh();
        });
        task.setOnFailed(event -> openStatusLabel.setText(
                "Could not open account: " + task.getException().getMessage()));
        new Thread(task, "account-open").start();
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

        long amount;
        try {
            amount = Money.toMinorUnits(input.get().trim());
        } catch (Exception e) {
            statusLabel.setText("Invalid amount.");
            return;
        }

        String agentId = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        String agentName = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getName() : "Agent";
        statusLabel.setText("Recording collection…");

        Task<CollectionResult> task = new Task<>() {
            @Override
            protected CollectionResult call() throws Exception {
                return collectionService.record(agentId, agentName, selected.getId(), amount, null, null);
            }
        };
        task.setOnSucceeded(event -> {
            CollectionResult result = task.getValue();
            statusLabel.setText("");

            Alert alert = new Alert(Alert.AlertType.INFORMATION,
                    "Collection recorded. New balance: " + Money.format(result.account().getBalance()));
            alert.setHeaderText(null);
            alert.showAndWait();

            refresh();
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not record collection: " + task.getException().getMessage()));
        new Thread(task, "collection-record").start();
    }

    @FXML
    private void onBuyShares() {
        statusLabel.setText("");
        SavingsAccount selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            statusLabel.setText("Select an account first.");
            return;
        }

        TextInputDialog dialog = new TextInputDialog();
        dialog.setTitle("Buy Shares");
        dialog.setHeaderText(selected.getAccountNumber());
        dialog.setContentText("Number of shares:");

        Optional<String> input = dialog.showAndWait();
        if (input.isEmpty() || input.get().isBlank()) {
            return;
        }

        int shares;
        try {
            shares = Integer.parseInt(input.get().trim());
        } catch (NumberFormatException e) {
            statusLabel.setText("Invalid number of shares.");
            return;
        }

        String agentId = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        String agentName = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getName() : "Agent";
        statusLabel.setText("Buying shares…");

        Task<SharesService.SharesResult> task = new Task<>() {
            @Override
            protected SharesService.SharesResult call() throws Exception {
                return sharesService.buyShares(agentId, agentName, selected.getId(), shares, null, null);
            }
        };
        task.setOnSucceeded(event -> {
            SharesService.SharesResult result = task.getValue();
            statusLabel.setText("");

            Alert alert = new Alert(Alert.AlertType.INFORMATION,
                    "Shares purchased. New balance: " + Money.format(result.account().getBalance())
                    + " (" + result.account().getShareCount() + " shares)");
            alert.setHeaderText(null);
            alert.showAndWait();

            refresh();
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not buy shares: " + task.getException().getMessage()));
        new Thread(task, "shares-buy").start();
    }
}
