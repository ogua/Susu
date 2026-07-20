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
import javafx.scene.control.TextField;
import javafx.scene.control.TextInputDialog;
import javafx.scene.layout.GridPane;
import models.GroupLoan;
import models.GroupLoanBorrower;
import models.LoanGroup;
import models.LoanProduct;
import service.GroupLoanRepaymentResult;
import service.GroupLoanService;
import service.LoanGroupService;
import service.LoanProductService;
import support.Money;

public class GroupLoansController {

    @FXML private TableView<GroupLoan> table;
    @FXML private TableColumn<GroupLoan, String> loanNumberColumn;
    @FXML private TableColumn<GroupLoan, String> loanGroupColumn;
    @FXML private TableColumn<GroupLoan, String> principalColumn;
    @FXML private TableColumn<GroupLoan, String> outstandingColumn;
    @FXML private TableColumn<GroupLoan, String> statusColumn;
    @FXML private Label statusLabel;

    @FXML private ComboBox<LoanGroup> loanGroupCombo;
    @FXML private ComboBox<LoanProduct> productCombo;
    @FXML private TextField amountField;
    @FXML private TextField notesField;
    @FXML private Label applyStatusLabel;

    @FXML private Button approveButton;
    @FXML private Button rejectButton;
    @FXML private Button disburseButton;
    @FXML private Button repayButton;

    @FXML private TableView<GroupLoanBorrower> borrowersTable;
    @FXML private TableColumn<GroupLoanBorrower, String> borrowerNameColumn;
    @FXML private TableColumn<GroupLoanBorrower, String> shareColumn;
    @FXML private TableColumn<GroupLoanBorrower, String> shareOutstandingColumn;

    private final LoanGroupService loanGroupService = new LoanGroupService();
    private final LoanProductService productService = new LoanProductService();
    private final GroupLoanService groupLoanService = new GroupLoanService();

    @FXML
    private void initialize() {
        loanNumberColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("loanNumber"));
        loanGroupColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getLoanGroup() != null ? data.getValue().getLoanGroup().getName() : ""));
        principalColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getPrincipalAmount())));
        outstandingColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getOutstandingBalance())));
        statusColumn.setCellValueFactory(data -> new SimpleStringProperty(data.getValue().getStatus().value()));

        borrowerNameColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getCustomer() != null ? data.getValue().getCustomer().fullName() : ""));
        shareColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getSharePrincipal())));
        shareOutstandingColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getShareOutstanding())));

        loanGroupCombo.setConverter(new javafx.util.StringConverter<>() {
            @Override
            public String toString(LoanGroup group) {
                return group == null ? "" : group.getName() + " (" + group.getCode() + ")";
            }

            @Override
            public LoanGroup fromString(String string) {
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

        table.getSelectionModel().selectedItemProperty().addListener((obs, old, selected) -> {
            updateActionButtons(selected);
            loadBorrowers(selected);
        });
        updateActionButtons(null);

        boolean canDecide = canDecideGroupLoans();
        approveButton.setVisible(canDecide);
        approveButton.setManaged(canDecide);
        rejectButton.setVisible(canDecide);
        rejectButton.setManaged(canDecide);
        disburseButton.setVisible(canDecide);
        disburseButton.setManaged(canDecide);

        loadLoanGroups();
        loadProducts();
        refresh();
    }

    /** Mirrors GroupLoanPolicy::approve/reject/disburse — only managers/admins decide group loans. */
    private boolean canDecideGroupLoans() {
        var user = SessionManager.getCurrentUser();
        if (user == null || user.getRole() == null) {
            return false;
        }
        String role = user.getRole().toLowerCase();
        return role.equals("company_admin") || role.equals("branch_manager");
    }

    private void updateActionButtons(GroupLoan selected) {
        boolean canDecide = canDecideGroupLoans();
        approveButton.setDisable(!canDecide || selected == null || selected.getStatus() != enums.GroupLoanStatus.APPLIED);
        rejectButton.setDisable(!canDecide || selected == null || selected.getStatus() != enums.GroupLoanStatus.APPLIED);
        disburseButton.setDisable(!canDecide || selected == null || selected.getStatus() != enums.GroupLoanStatus.APPROVED);
        repayButton.setDisable(selected == null || selected.getStatus() != enums.GroupLoanStatus.DISBURSED);
    }

    private void loadLoanGroups() {
        Task<List<LoanGroup>> task = new Task<>() {
            @Override
            protected List<LoanGroup> call() throws Exception {
                return loanGroupService.findAll().stream().filter(LoanGroup::isActive).toList();
            }
        };
        task.setOnSucceeded(event -> loanGroupCombo.setItems(FXCollections.observableArrayList(task.getValue())));
        new Thread(task, "group-loan-groups-load").start();
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
        new Thread(task, "group-loan-products-load").start();
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading group loans…");
        Task<List<GroupLoan>> task = new Task<>() {
            @Override
            protected List<GroupLoan> call() throws Exception {
                return groupLoanService.findAll();
            }
        };
        task.setOnSucceeded(event -> {
            table.setItems(FXCollections.observableArrayList(task.getValue()));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load group loans: " + task.getException().getMessage()));
        new Thread(task, "group-loans-refresh").start();
    }

    private void loadBorrowers(GroupLoan selected) {
        if (selected == null) {
            borrowersTable.setItems(FXCollections.observableArrayList());
            return;
        }
        Task<List<GroupLoanBorrower>> task = new Task<>() {
            @Override
            protected List<GroupLoanBorrower> call() throws Exception {
                return groupLoanService.findBorrowers(selected.getId());
            }
        };
        task.setOnSucceeded(event -> borrowersTable.setItems(FXCollections.observableArrayList(task.getValue())));
        new Thread(task, "group-loan-borrowers-load").start();
    }

    @FXML
    private void onSubmitApplication() {
        applyStatusLabel.setText("");
        LoanGroup loanGroup = loanGroupCombo.getValue();
        LoanProduct product = productCombo.getValue();
        long amount = parsedAmount();

        if (loanGroup == null) {
            applyStatusLabel.setText("Select a loan group.");
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
        String notes = notesField.getText().isBlank() ? null : notesField.getText().trim();
        applyStatusLabel.setText("Submitting…");

        Task<GroupLoan> task = new Task<>() {
            @Override
            protected GroupLoan call() throws Exception {
                return groupLoanService.apply(agentId, loanGroup.getId(), product.getId(), amount, notes, null);
            }
        };
        task.setOnSucceeded(event -> {
            amountField.clear();
            notesField.clear();
            applyStatusLabel.setText("");
            refresh();
        });
        task.setOnFailed(event -> applyStatusLabel.setText(
                "Could not submit application: " + task.getException().getMessage()));
        new Thread(task, "group-loan-apply").start();
    }

    @FXML
    private void onApprove() {
        GroupLoan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }
        String approvedBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;

        runGroupLoanAction("Approving…", () -> groupLoanService.approve(selected.getId(), approvedBy),
                "Could not approve group loan");
    }

    @FXML
    private void onReject() {
        GroupLoan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }

        TextInputDialog dialog = new TextInputDialog();
        dialog.setTitle("Reject Group Loan");
        dialog.setHeaderText(selected.getLoanNumber());
        dialog.setContentText("Reason:");
        Optional<String> input = dialog.showAndWait();
        if (input.isEmpty() || input.get().isBlank()) {
            return;
        }

        String rejectedBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        runGroupLoanAction("Rejecting…", () -> groupLoanService.reject(selected.getId(), rejectedBy, input.get().trim()),
                "Could not reject group loan");
    }

    @FXML
    private void onDisburse() {
        GroupLoan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }
        String disbursedBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;

        runGroupLoanAction("Disbursing…", () -> groupLoanService.disburse(selected.getId(), disbursedBy),
                "Could not disburse group loan");
    }

    @FXML
    private void onRecordRepayment() {
        GroupLoan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }

        Task<List<GroupLoanBorrower>> loadTask = new Task<>() {
            @Override
            protected List<GroupLoanBorrower> call() throws Exception {
                return groupLoanService.findBorrowers(selected.getId());
            }
        };
        loadTask.setOnSucceeded(loadEvent -> {
            List<GroupLoanBorrower> borrowers = loadTask.getValue();
            if (borrowers.isEmpty()) {
                statusLabel.setText("This group loan has no borrowers.");
                return;
            }

            Optional<RepaymentInput> input = showRepaymentDialog(selected, borrowers);
            if (input.isEmpty()) {
                return;
            }

            String recordedBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
            statusLabel.setText("Recording repayment…");

            Task<GroupLoanRepaymentResult> task = new Task<>() {
                @Override
                protected GroupLoanRepaymentResult call() throws Exception {
                    return groupLoanService.recordRepayment(input.get().borrower().getId(), input.get().amount(), recordedBy, null, null);
                }
            };
            task.setOnSucceeded(event -> {
                statusLabel.setText("");
                GroupLoanRepaymentResult result = task.getValue();

                Alert alert = new Alert(Alert.AlertType.INFORMATION,
                        "Repayment recorded. Outstanding balance: " + Money.format(result.groupLoan().getOutstandingBalance()));
                alert.setHeaderText(null);
                alert.showAndWait();

                refresh();
                loadBorrowers(selected);
            });
            task.setOnFailed(event -> statusLabel.setText(
                    "Could not record repayment: " + task.getException().getMessage()));
            new Thread(task, "group-loan-repayment").start();
        });
        new Thread(loadTask, "group-loan-borrowers-for-repayment").start();
    }

    /** TextInputDialog only supports one field — a group loan repayment needs both a payer and an amount. */
    private Optional<RepaymentInput> showRepaymentDialog(GroupLoan groupLoan, List<GroupLoanBorrower> borrowers) {
        Dialog<RepaymentInput> dialog = new Dialog<>();
        dialog.setTitle("Record Repayment");
        dialog.setHeaderText(groupLoan.getLoanNumber() + " — outstanding " + Money.format(groupLoan.getOutstandingBalance()));

        ButtonType recordButtonType = new ButtonType("Record", ButtonBar.ButtonData.OK_DONE);
        dialog.getDialogPane().getButtonTypes().addAll(recordButtonType, ButtonType.CANCEL);

        ComboBox<GroupLoanBorrower> borrowerCombo = new ComboBox<>(FXCollections.observableArrayList(borrowers));
        borrowerCombo.getSelectionModel().selectFirst();
        TextField amountField = new TextField();
        amountField.setPromptText("Amount (GHS)");

        GridPane grid = new GridPane();
        grid.setHgap(8);
        grid.setVgap(8);
        grid.setPadding(new Insets(10));
        grid.add(new Label("Paying member:"), 0, 0);
        grid.add(borrowerCombo, 1, 0);
        grid.add(new Label("Amount (GHS):"), 0, 1);
        grid.add(amountField, 1, 1);
        dialog.getDialogPane().setContent(grid);

        dialog.setResultConverter(buttonType -> {
            if (buttonType != recordButtonType) {
                return null;
            }
            GroupLoanBorrower borrower = borrowerCombo.getValue();
            if (borrower == null) {
                return null;
            }
            try {
                long amount = Money.toMinorUnits(amountField.getText().trim());
                return amount > 0 ? new RepaymentInput(borrower, amount) : null;
            } catch (Exception e) {
                return null;
            }
        });

        return dialog.showAndWait();
    }

    private record RepaymentInput(GroupLoanBorrower borrower, long amount) {
    }

    private void runGroupLoanAction(String progressMessage, GroupLoanAction action, String failureMessage) {
        statusLabel.setText(progressMessage);
        Task<GroupLoan> task = new Task<>() {
            @Override
            protected GroupLoan call() throws Exception {
                return action.run();
            }
        };
        task.setOnSucceeded(event -> {
            statusLabel.setText("");
            refresh();
        });
        task.setOnFailed(event -> statusLabel.setText(failureMessage + ": " + task.getException().getMessage()));
        new Thread(task, "group-loan-action").start();
    }

    private long parsedAmount() {
        try {
            return amountField.getText().isBlank() ? 0 : Money.toMinorUnits(amountField.getText().trim());
        } catch (Exception e) {
            return 0;
        }
    }

    @FunctionalInterface
    private interface GroupLoanAction {
        GroupLoan run() throws Exception;
    }
}
