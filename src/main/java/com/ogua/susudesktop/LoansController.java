package com.ogua.susudesktop;

import db.SessionManager;
import java.util.List;
import java.util.Optional;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.geometry.Insets;
import javafx.scene.control.Alert;
import javafx.scene.control.Button;
import javafx.scene.control.ButtonBar;
import javafx.scene.control.ButtonType;
import javafx.scene.control.ComboBox;
import javafx.scene.control.Dialog;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import javafx.scene.control.TextArea;
import javafx.scene.control.TextField;
import javafx.scene.control.TextInputDialog;
import javafx.scene.layout.GridPane;
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
    @FXML private Button restructureButton;
    @FXML private Button topUpButton;
    @FXML private Button writeOffButton;

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
        restructureButton.setVisible(canDecide);
        restructureButton.setManaged(canDecide);
        topUpButton.setVisible(canDecide);
        topUpButton.setManaged(canDecide);
        writeOffButton.setVisible(canDecide);
        writeOffButton.setManaged(canDecide);

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
        restructureButton.setDisable(!canDecide || selected == null || selected.getStatus() != enums.LoanStatus.DISBURSED);
        topUpButton.setDisable(!canDecide || selected == null || selected.getStatus() != enums.LoanStatus.DISBURSED);
        writeOffButton.setDisable(!canDecide || selected == null || selected.getStatus() != enums.LoanStatus.DISBURSED);
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

    @FXML
    private void onRestructure() {
        Loan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }

        Optional<RestructureInput> input = showRestructureDialog(selected);
        if (input.isEmpty()) {
            return;
        }

        String restructuredBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        runLoanAction("Restructuring…",
                () -> loanService.restructure(selected.getId(), restructuredBy, input.get().product().getId(), input.get().reason()),
                "Could not restructure loan");
    }

    @FXML
    private void onTopUp() {
        Loan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }

        Optional<TopUpInput> input = showTopUpDialog(selected);
        if (input.isEmpty()) {
            return;
        }

        String toppedUpBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        runLoanAction("Topping up…",
                () -> loanService.topUp(selected.getId(), toppedUpBy, input.get().amount(), input.get().reason()),
                "Could not top up loan");
    }

    @FXML
    private void onWriteOff() {
        Loan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }

        String writtenOffBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;

        Task<List<SavingsAccount>> load = new Task<>() {
            @Override protected List<SavingsAccount> call() throws Exception {
                return accountService.findByCustomer(selected.getCustomerId());
            }
        };
        load.setOnSucceeded(event -> SavingsAccountDialogs.writeOff("Write Off Loan",
                selected.getLoanNumber() + " — outstanding " + Money.format(selected.getOutstandingBalance())
                        + ". This permanently closes the loan and recognizes the remaining balance as a loss."
                        + " This cannot be undone.",
                load.getValue())
                .ifPresent(input -> runLoanAction("Writing off…",
                        () -> loanService.writeOff(selected.getId(), writtenOffBy, input.reason(),
                                input.savingsAccountId(), input.savingsAmountApplied()),
                        "Could not write off loan")));
        load.setOnFailed(event -> statusLabel.setText(
                "Could not load savings accounts: " + load.getException().getMessage()));
        new Thread(load, "loan-savings-load").start();
    }

    /** TextInputDialog only supports one field — restructuring needs both a new product and a reason. */
    private Optional<RestructureInput> showRestructureDialog(Loan loan) {
        Dialog<RestructureInput> dialog = new Dialog<>();
        dialog.setTitle("Restructure Loan");
        dialog.setHeaderText(loan.getLoanNumber() + " — this closes the loan and opens a new one on new terms.");

        ButtonType restructureButtonType = new ButtonType("Restructure", ButtonBar.ButtonData.OK_DONE);
        dialog.getDialogPane().getButtonTypes().addAll(restructureButtonType, ButtonType.CANCEL);

        ComboBox<LoanProduct> newProductCombo = new ComboBox<>(FXCollections.observableArrayList(productCombo.getItems()));
        newProductCombo.setConverter(productCombo.getConverter());
        newProductCombo.getItems().stream()
                .filter(p -> p.getId().equals(loan.getLoanProductId()))
                .findFirst()
                .ifPresentOrElse(newProductCombo.getSelectionModel()::select,
                        () -> newProductCombo.getSelectionModel().selectFirst());
        TextArea reasonArea = new TextArea();
        reasonArea.setPrefRowCount(3);
        reasonArea.setPromptText("Reason");

        GridPane grid = new GridPane();
        grid.setHgap(8);
        grid.setVgap(8);
        grid.setPadding(new Insets(10));
        grid.add(new Label("New product:"), 0, 0);
        grid.add(newProductCombo, 1, 0);
        grid.add(new Label("Reason:"), 0, 1);
        grid.add(reasonArea, 1, 1);
        dialog.getDialogPane().setContent(grid);

        dialog.setResultConverter(buttonType -> {
            if (buttonType != restructureButtonType) {
                return null;
            }
            LoanProduct product = newProductCombo.getValue();
            String reason = reasonArea.getText().trim();
            return (product != null && !reason.isBlank()) ? new RestructureInput(product, reason) : null;
        });

        return dialog.showAndWait();
    }

    /** TextInputDialog only supports one field — a top-up needs both an amount and a reason. */
    private Optional<TopUpInput> showTopUpDialog(Loan loan) {
        Dialog<TopUpInput> dialog = new Dialog<>();
        dialog.setTitle("Top Up Loan");
        dialog.setHeaderText(loan.getLoanNumber() + " — this closes the loan and opens a new one for the rolled-over"
                + " balance plus the top-up cash disbursed today.");

        ButtonType topUpButtonType = new ButtonType("Top Up", ButtonBar.ButtonData.OK_DONE);
        dialog.getDialogPane().getButtonTypes().addAll(topUpButtonType, ButtonType.CANCEL);

        TextField amountField = new TextField();
        amountField.setPromptText("Amount (GHS)");
        TextArea reasonArea = new TextArea();
        reasonArea.setPrefRowCount(3);
        reasonArea.setPromptText("Reason");

        GridPane grid = new GridPane();
        grid.setHgap(8);
        grid.setVgap(8);
        grid.setPadding(new Insets(10));
        grid.add(new Label("Top-up amount (GHS):"), 0, 0);
        grid.add(amountField, 1, 0);
        grid.add(new Label("Reason:"), 0, 1);
        grid.add(reasonArea, 1, 1);
        dialog.getDialogPane().setContent(grid);

        dialog.setResultConverter(buttonType -> {
            if (buttonType != topUpButtonType) {
                return null;
            }
            String reason = reasonArea.getText().trim();
            if (reason.isBlank()) {
                return null;
            }
            try {
                long amount = Money.toMinorUnits(amountField.getText().trim());
                return amount > 0 ? new TopUpInput(amount, reason) : null;
            } catch (Exception e) {
                return null;
            }
        });

        return dialog.showAndWait();
    }

    private record RestructureInput(LoanProduct product, String reason) {
    }

    private record TopUpInput(long amount, String reason) {
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
