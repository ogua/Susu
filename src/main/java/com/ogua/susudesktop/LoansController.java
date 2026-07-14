package com.ogua.susudesktop;

import db.SessionManager;
import java.util.List;
import java.util.Optional;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
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
import models.Loan;
import models.LoanProduct;
import models.SavingsAccount;
import service.CustomerService;
import service.LoanEligibilityResult;
import service.LoanEligibilityService;
import service.LoanProductService;
import service.LoanRepaymentResult;
import service.LoanService;
import service.SavingsAccountService;
import support.Money;

public class LoansController {

    @FXML private TableView<Loan> table;
    @FXML private TableColumn<Loan, String> loanNumberColumn;
    @FXML private TableColumn<Loan, String> customerColumn;
    @FXML private TableColumn<Loan, String> principalColumn;
    @FXML private TableColumn<Loan, String> outstandingColumn;
    @FXML private TableColumn<Loan, String> statusColumn;
    @FXML private Label statusLabel;

    @FXML private TextField customerSearchField;
    @FXML private ComboBox<Customer> customerCombo;
    @FXML private ComboBox<LoanProduct> productCombo;
    @FXML private TextField amountField;
    @FXML private TextField guarantorNameField;
    @FXML private TextField guarantorPhoneField;
    @FXML private Label eligibilityLabel;
    @FXML private Label applyStatusLabel;

    @FXML private Button approveButton;
    @FXML private Button rejectButton;
    @FXML private Button disburseButton;
    @FXML private Button repayButton;

    private final CustomerService customerService = new CustomerService();
    private final SavingsAccountService accountService = new SavingsAccountService();
    private final LoanProductService productService = new LoanProductService();
    private final LoanEligibilityService eligibilityService = new LoanEligibilityService();
    private final LoanService loanService = new LoanService();

    @FXML
    private void initialize() {
        loanNumberColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("loanNumber"));
        customerColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getCustomer() != null ? data.getValue().getCustomer().fullName() : ""));
        principalColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getPrincipalAmount())));
        outstandingColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getOutstandingBalance())));
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
            public String toString(LoanProduct product) {
                return product == null ? "" : product.getName();
            }

            @Override
            public LoanProduct fromString(String string) {
                return null;
            }
        });

        table.getSelectionModel().selectedItemProperty().addListener((obs, old, selected) -> updateActionButtons(selected));
        updateActionButtons(null);

        boolean canDecide = canDecideLoans();
        approveButton.setVisible(canDecide);
        approveButton.setManaged(canDecide);
        rejectButton.setVisible(canDecide);
        rejectButton.setManaged(canDecide);
        disburseButton.setVisible(canDecide);
        disburseButton.setManaged(canDecide);

        loadProducts();
        refresh();
    }

    /** Mirrors LoanPolicy::approve/reject/disburse — only managers/admins decide loans. */
    private boolean canDecideLoans() {
        var user = SessionManager.getCurrentUser();
        if (user == null || user.getRole() == null) {
            return false;
        }
        String role = user.getRole().toLowerCase();
        return role.equals("company_admin") || role.equals("branch_manager");
    }

    private void updateActionButtons(Loan selected) {
        boolean canDecide = canDecideLoans();
        approveButton.setDisable(!canDecide || selected == null || selected.getStatus() != enums.LoanStatus.APPLIED);
        rejectButton.setDisable(!canDecide || selected == null || selected.getStatus() != enums.LoanStatus.APPLIED);
        disburseButton.setDisable(!canDecide || selected == null || selected.getStatus() != enums.LoanStatus.APPROVED);
        repayButton.setDisable(selected == null || selected.getStatus() != enums.LoanStatus.DISBURSED);
    }

    private void loadProducts() {
        Task<List<LoanProduct>> task = new Task<>() {
            @Override
            protected List<LoanProduct> call() throws Exception {
                LoanProduct defaultProduct = productService.getOrCreateDefault();
                List<LoanProduct> products = productService.findActive();
                return products.isEmpty() ? List.of(defaultProduct) : products;
            }
        };
        task.setOnSucceeded(event -> {
            productCombo.setItems(FXCollections.observableArrayList(task.getValue()));
            productCombo.getSelectionModel().selectFirst();
        });
        new Thread(task, "loan-products-load").start();
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading loans…");
        Task<List<Loan>> task = new Task<>() {
            @Override
            protected List<Loan> call() throws Exception {
                return loanService.findAll();
            }
        };
        task.setOnSucceeded(event -> {
            table.setItems(FXCollections.observableArrayList(task.getValue()));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load loans: " + task.getException().getMessage()));
        new Thread(task, "loans-refresh").start();
    }

    @FXML
    private void onFindCustomer() {
        applyStatusLabel.setText("Searching…");
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
            applyStatusLabel.setText("");
            eligibilityLabel.setText("");
        });
        task.setOnFailed(event -> applyStatusLabel.setText(
                "Search failed: " + task.getException().getMessage()));
        new Thread(task, "loan-customer-search").start();
    }

    @FXML
    private void onCheckEligibility() {
        eligibilityLabel.setText("");
        Customer customer = customerCombo.getValue();
        long amount = parsedAmount();
        if (customer == null || amount <= 0) {
            applyStatusLabel.setText("Select a customer and enter an amount first.");
            return;
        }

        applyStatusLabel.setText("Checking…");
        Task<LoanEligibilityResult> task = new Task<>() {
            @Override
            protected LoanEligibilityResult call() throws Exception {
                List<SavingsAccount> accounts = accountService.findByCustomer(customer.getId());
                if (accounts.isEmpty()) {
                    throw new IllegalStateException("This customer has no savings account to evaluate.");
                }
                return eligibilityService.evaluate(accounts.get(0), amount);
            }
        };
        task.setOnSucceeded(event -> {
            LoanEligibilityResult result = task.getValue();
            applyStatusLabel.setText("");
            eligibilityLabel.setText(result.eligible()
                    ? "Eligible"
                    : "Not eligible: " + String.join("; ", result.reasons()));
        });
        task.setOnFailed(event -> applyStatusLabel.setText(
                "Could not check eligibility: " + task.getException().getMessage()));
        new Thread(task, "loan-eligibility-check").start();
    }

    @FXML
    private void onSubmitApplication() {
        applyStatusLabel.setText("");
        Customer customer = customerCombo.getValue();
        LoanProduct product = productCombo.getValue();
        long amount = parsedAmount();

        if (customer == null) {
            applyStatusLabel.setText("Find and select a customer first.");
            return;
        }
        if (product == null) {
            applyStatusLabel.setText("Select a loan product.");
            return;
        }
        if (amount <= 0) {
            applyStatusLabel.setText("Enter a valid amount.");
            return;
        }

        String agentId = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        String guarantorName = guarantorNameField.getText().isBlank() ? null : guarantorNameField.getText().trim();
        String guarantorPhone = guarantorPhoneField.getText().isBlank() ? null : guarantorPhoneField.getText().trim();
        applyStatusLabel.setText("Submitting…");

        Task<Loan> task = new Task<>() {
            @Override
            protected Loan call() throws Exception {
                List<SavingsAccount> accounts = accountService.findByCustomer(customer.getId());
                String savingsAccountId = accounts.isEmpty() ? null : accounts.get(0).getId();
                return loanService.apply(agentId, customer.getId(), product.getId(), amount, savingsAccountId,
                        guarantorName, guarantorPhone, null, null);
            }
        };
        task.setOnSucceeded(event -> {
            customerSearchField.clear();
            customerCombo.getItems().clear();
            amountField.clear();
            guarantorNameField.clear();
            guarantorPhoneField.clear();
            eligibilityLabel.setText("");
            applyStatusLabel.setText("");
            refresh();
        });
        task.setOnFailed(event -> applyStatusLabel.setText(
                "Could not submit application: " + task.getException().getMessage()));
        new Thread(task, "loan-apply").start();
    }

    @FXML
    private void onApprove() {
        Loan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }
        String approvedBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;

        runLoanAction("Approving…", () -> loanService.approve(selected.getId(), approvedBy),
                "Could not approve loan");
    }

    @FXML
    private void onReject() {
        Loan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }

        TextInputDialog dialog = new TextInputDialog();
        dialog.setTitle("Reject Loan");
        dialog.setHeaderText(selected.getLoanNumber());
        dialog.setContentText("Reason:");
        Optional<String> input = dialog.showAndWait();
        if (input.isEmpty() || input.get().isBlank()) {
            return;
        }

        String rejectedBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        runLoanAction("Rejecting…", () -> loanService.reject(selected.getId(), rejectedBy, input.get().trim()),
                "Could not reject loan");
    }

    @FXML
    private void onDisburse() {
        Loan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }
        String disbursedBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;

        runLoanAction("Disbursing…", () -> loanService.disburse(selected.getId(), disbursedBy),
                "Could not disburse loan");
    }

    @FXML
    private void onRecordRepayment() {
        Loan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }

        TextInputDialog dialog = new TextInputDialog();
        dialog.setTitle("Record Repayment");
        dialog.setHeaderText(selected.getLoanNumber() + " — outstanding " + Money.format(selected.getOutstandingBalance()));
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

        String recordedBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        statusLabel.setText("Recording repayment…");

        long finalAmount = amount;
        Task<LoanRepaymentResult> task = new Task<>() {
            @Override
            protected LoanRepaymentResult call() throws Exception {
                return loanService.recordRepayment(selected.getId(), finalAmount, recordedBy, null, null);
            }
        };
        task.setOnSucceeded(event -> {
            statusLabel.setText("");
            LoanRepaymentResult result = task.getValue();

            Alert alert = new Alert(Alert.AlertType.INFORMATION,
                    "Repayment recorded. Outstanding balance: " + Money.format(result.loan().getOutstandingBalance()));
            alert.setHeaderText(null);
            alert.showAndWait();

            refresh();
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not record repayment: " + task.getException().getMessage()));
        new Thread(task, "loan-repayment").start();
    }

    private void runLoanAction(String progressMessage, LoanAction action, String failureMessage) {
        statusLabel.setText(progressMessage);
        Task<Loan> task = new Task<>() {
            @Override
            protected Loan call() throws Exception {
                return action.run();
            }
        };
        task.setOnSucceeded(event -> {
            statusLabel.setText("");
            refresh();
        });
        task.setOnFailed(event -> statusLabel.setText(failureMessage + ": " + task.getException().getMessage()));
        new Thread(task, "loan-action").start();
    }

    private long parsedAmount() {
        try {
            return amountField.getText().isBlank() ? 0 : Money.toMinorUnits(amountField.getText().trim());
        } catch (Exception e) {
            return 0;
        }
    }

    @FunctionalInterface
    private interface LoanAction {
        Loan run() throws Exception;
    }
}
