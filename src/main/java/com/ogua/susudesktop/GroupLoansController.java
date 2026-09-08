package com.ogua.susudesktop;

import db.SessionManager;
import enums.LoanFrequency;
import java.time.LocalDate;
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
import models.Customer;
import models.GroupLoan;
import models.GroupLoanInstallment;
import models.LoanGroup;
import service.CustomerService;
import service.GroupLoanRepaymentResult;
import service.GroupLoanService;
import service.LoanGroupService;
import service.PeriodicScheduleGenerator;
import support.Money;

/**
 * Group loans — one loan per group member. Issue a loan (loan amount +
 * security deposit + a directly-entered periodic repayment amount), then
 * record the deposit, activate, take repayments, or apply the deposit against
 * the balance. Write-off is manager-tier. No approve/reject/disburse step.
 */
public class GroupLoansController {

    @FXML private TableView<GroupLoan> table;
    @FXML private TableColumn<GroupLoan, String> loanNumberColumn;
    @FXML private TableColumn<GroupLoan, String> loanGroupColumn;
    @FXML private TableColumn<GroupLoan, String> memberColumn;
    @FXML private TableColumn<GroupLoan, String> principalColumn;
    @FXML private TableColumn<GroupLoan, String> depositColumn;
    @FXML private TableColumn<GroupLoan, String> periodicColumn;
    @FXML private TableColumn<GroupLoan, String> outstandingColumn;
    @FXML private TableColumn<GroupLoan, String> depositStatusColumn;
    @FXML private TableColumn<GroupLoan, String> statusColumn;
    @FXML private Label statusLabel;

    @FXML private ComboBox<LoanGroup> loanGroupCombo;
    @FXML private TextField customerSearchField;
    @FXML private ComboBox<Customer> customerCombo;
    @FXML private TextField principalField;
    @FXML private TextField depositField;
    @FXML private TextField periodicField;
    @FXML private ComboBox<LoanFrequency> frequencyCombo;
    @FXML private DatePicker startDatePicker;
    @FXML private TextField notesField;
    @FXML private Label schedulePreviewLabel;
    @FXML private Label applyStatusLabel;

    @FXML private Button depositButton;
    @FXML private Button activateButton;
    @FXML private Button repayButton;
    @FXML private Button applyDepositButton;
    @FXML private Button writeOffButton;

    @FXML private TableView<GroupLoanInstallment> installmentsTable;
    @FXML private TableColumn<GroupLoanInstallment, String> seqColumn;
    @FXML private TableColumn<GroupLoanInstallment, String> dueDateColumn;
    @FXML private TableColumn<GroupLoanInstallment, String> amountDueColumn;
    @FXML private TableColumn<GroupLoanInstallment, String> amountPaidColumn;
    @FXML private TableColumn<GroupLoanInstallment, String> installmentStatusColumn;

    private final LoanGroupService loanGroupService = new LoanGroupService();
    private final CustomerService customerService = new CustomerService();
    private final GroupLoanService groupLoanService = new GroupLoanService();
    private final PeriodicScheduleGenerator scheduleGenerator = new PeriodicScheduleGenerator();

    @FXML
    private void initialize() {
        loanNumberColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("loanNumber"));
        loanGroupColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getLoanGroup() != null ? data.getValue().getLoanGroup().getName() : ""));
        memberColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getCustomer() != null ? data.getValue().getCustomer().fullName() : ""));
        principalColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getPrincipalAmount())));
        depositColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getSecurityDepositAmount())));
        periodicColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getPeriodicAmount())));
        outstandingColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getOutstandingBalance())));
        depositStatusColumn.setCellValueFactory(data -> new SimpleStringProperty(data.getValue().getDepositStatus().value()));
        statusColumn.setCellValueFactory(data -> new SimpleStringProperty(data.getValue().getStatus().value()));

        seqColumn.setCellValueFactory(data -> new SimpleStringProperty(String.valueOf(data.getValue().getSequence())));
        dueDateColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getDueDate() != null ? data.getValue().getDueDate().toString() : ""));
        amountDueColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getAmountDue())));
        amountPaidColumn.setCellValueFactory(data -> new SimpleStringProperty(Money.format(data.getValue().getAmountPaid())));
        installmentStatusColumn.setCellValueFactory(data -> new SimpleStringProperty(data.getValue().getStatus().value()));

        loanGroupCombo.setConverter(new javafx.util.StringConverter<>() {
            @Override public String toString(LoanGroup group) {
                return group == null ? "" : group.getName() + " (" + group.getCode() + ")";
            }
            @Override public LoanGroup fromString(String string) { return null; }
        });
        customerCombo.setConverter(new javafx.util.StringConverter<>() {
            @Override public String toString(Customer customer) {
                return customer == null ? "" : customer.fullName() + " (" + customer.getCustomerCode() + ")";
            }
            @Override public Customer fromString(String string) { return null; }
        });
        frequencyCombo.setItems(FXCollections.observableArrayList(LoanFrequency.values()));
        frequencyCombo.getSelectionModel().select(LoanFrequency.WEEKLY);
        startDatePicker.setValue(LocalDate.now());

        principalField.textProperty().addListener((o, a, b) -> updatePreview());
        periodicField.textProperty().addListener((o, a, b) -> updatePreview());
        frequencyCombo.valueProperty().addListener((o, a, b) -> updatePreview());

        boolean canWriteOff = canWriteOff();
        writeOffButton.setVisible(canWriteOff);
        writeOffButton.setManaged(canWriteOff);

        table.getSelectionModel().selectedItemProperty().addListener((obs, old, selected) -> {
            updateActionButtons(selected);
            loadInstallments(selected);
        });
        updateActionButtons(null);

        loadLoanGroups();
        refresh();
        updatePreview();
    }

    /** Mirrors GroupLoanPolicy::writeOff — only managers/admins can write a loan off. */
    private boolean canWriteOff() {
        var user = SessionManager.getCurrentUser();
        if (user == null || user.getRole() == null) {
            return false;
        }
        String role = user.getRole().toLowerCase();
        return role.equals("company_admin") || role.equals("branch_manager");
    }

    private void updateActionButtons(GroupLoan selected) {
        boolean pendingDeposit = selected != null
                && selected.getStatus() == enums.GroupLoanStatus.DRAFT
                && selected.getDepositStatus() == enums.DepositStatus.PENDING;
        boolean readyToActivate = selected != null
                && selected.getStatus() == enums.GroupLoanStatus.DRAFT
                && selected.getDepositStatus() == enums.DepositStatus.HELD;
        boolean active = selected != null && selected.getStatus() == enums.GroupLoanStatus.ACTIVE;
        boolean depositHeld = active && selected.getDepositStatus() == enums.DepositStatus.HELD;

        depositButton.setDisable(!pendingDeposit);
        activateButton.setDisable(!readyToActivate);
        repayButton.setDisable(!active);
        applyDepositButton.setDisable(!depositHeld);
        writeOffButton.setDisable(!canWriteOff() || !active);
    }

    private void updatePreview() {
        long principal = parse(principalField.getText());
        long periodic = parse(periodicField.getText());
        LoanFrequency frequency = frequencyCombo.getValue() != null ? frequencyCombo.getValue() : LoanFrequency.WEEKLY;
        if (principal <= 0 || periodic <= 0) {
            schedulePreviewLabel.setText("Enter a loan amount and a periodic amount to preview the schedule.");
            return;
        }
        try {
            int count = scheduleGenerator.periodCount(principal, periodic);
            long last = principal - periodic * (count - 1);
            schedulePreviewLabel.setText(count + " " + frequency.value() + " payment" + (count == 1 ? "" : "s")
                    + " of " + Money.format(periodic) + "; final payment " + Money.format(last) + ".");
        } catch (RuntimeException e) {
            schedulePreviewLabel.setText(e.getMessage());
        }
    }

    private void loadLoanGroups() {
        Task<List<LoanGroup>> task = new Task<>() {
            @Override protected List<LoanGroup> call() throws Exception {
                return loanGroupService.findAll().stream().filter(LoanGroup::isActive).toList();
            }
        };
        task.setOnSucceeded(e -> loanGroupCombo.setItems(FXCollections.observableArrayList(task.getValue())));
        new Thread(task, "group-loan-groups-load").start();
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading group loans…");
        Task<List<GroupLoan>> task = new Task<>() {
            @Override protected List<GroupLoan> call() throws Exception {
                return groupLoanService.findAll();
            }
        };
        task.setOnSucceeded(e -> {
            table.setItems(FXCollections.observableArrayList(task.getValue()));
            statusLabel.setText("");
        });
        task.setOnFailed(e -> statusLabel.setText("Could not load group loans: " + task.getException().getMessage()));
        new Thread(task, "group-loans-refresh").start();
    }

    private void loadInstallments(GroupLoan selected) {
        if (selected == null) {
            installmentsTable.setItems(FXCollections.observableArrayList());
            return;
        }
        Task<List<GroupLoanInstallment>> task = new Task<>() {
            @Override protected List<GroupLoanInstallment> call() throws Exception {
                return groupLoanService.findInstallments(selected.getId());
            }
        };
        task.setOnSucceeded(e -> installmentsTable.setItems(FXCollections.observableArrayList(task.getValue())));
        new Thread(task, "group-loan-installments-load").start();
    }

    @FXML
    private void onFindCustomer() {
        applyStatusLabel.setText("Searching…");
        Task<List<Customer>> task = new Task<>() {
            @Override protected List<Customer> call() throws Exception {
                return customerService.search(customerSearchField.getText());
            }
        };
        task.setOnSucceeded(e -> {
            customerCombo.setItems(FXCollections.observableArrayList(task.getValue()));
            if (!task.getValue().isEmpty()) {
                customerCombo.getSelectionModel().selectFirst();
            }
            applyStatusLabel.setText("");
        });
        task.setOnFailed(e -> applyStatusLabel.setText("Search failed: " + task.getException().getMessage()));
        new Thread(task, "group-loan-customer-search").start();
    }

    @FXML
    private void onIssueLoan() {
        applyStatusLabel.setText("");
        LoanGroup loanGroup = loanGroupCombo.getValue();
        Customer customer = customerCombo.getValue();
        long principal = parse(principalField.getText());
        long deposit = parse(depositField.getText());
        long periodic = parse(periodicField.getText());
        LoanFrequency frequency = frequencyCombo.getValue();
        LocalDate startDate = startDatePicker.getValue();

        if (loanGroup == null) { applyStatusLabel.setText("Select a loan group."); return; }
        if (customer == null) { applyStatusLabel.setText("Find and select a member."); return; }
        if (principal <= 0) { applyStatusLabel.setText("Enter a valid loan amount."); return; }
        if (periodic <= 0) { applyStatusLabel.setText("Enter a valid periodic amount."); return; }
        if (frequency == null) { applyStatusLabel.setText("Select a frequency."); return; }
        if (startDate == null) { applyStatusLabel.setText("Pick a first payment date."); return; }

        String agentId = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        String notes = notesField.getText().isBlank() ? null : notesField.getText().trim();
        applyStatusLabel.setText("Issuing…");

        Task<GroupLoan> task = new Task<>() {
            @Override protected GroupLoan call() throws Exception {
                return groupLoanService.issue(agentId, loanGroup.getId(), customer.getId(), principal, deposit,
                        periodic, frequency, startDate, notes, null);
            }
        };
        task.setOnSucceeded(e -> {
            principalField.clear();
            depositField.clear();
            periodicField.clear();
            notesField.clear();
            applyStatusLabel.setText("");
            refresh();
        });
        task.setOnFailed(e -> applyStatusLabel.setText("Could not issue loan: " + task.getException().getMessage()));
        new Thread(task, "group-loan-issue").start();
    }

    @FXML
    private void onRecordDeposit() {
        GroupLoan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }
        String recordedBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        long amount = selected.getSecurityDepositAmount();
        runAction("Recording deposit…",
                () -> groupLoanService.recordDeposit(selected.getId(), amount, recordedBy, null, null),
                "Could not record deposit");
    }

    @FXML
    private void onActivate() {
        GroupLoan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }
        String activatedBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        runAction("Activating…", () -> groupLoanService.activate(selected.getId(), activatedBy),
                "Could not activate loan");
    }

    @FXML
    private void onApplyDeposit() {
        GroupLoan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }
        String appliedBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        runAction("Applying deposit…", () -> groupLoanService.applyDeposit(selected.getId(), appliedBy, null),
                "Could not apply deposit");
    }

    @FXML
    private void onRecordRepayment() {
        GroupLoan selected = table.getSelectionModel().getSelectedItem();
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
        } catch (RuntimeException e) {
            statusLabel.setText("Enter a valid amount.");
            return;
        }
        if (amount <= 0) {
            statusLabel.setText("Enter a valid amount.");
            return;
        }

        String recordedBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        statusLabel.setText("Recording repayment…");

        Task<GroupLoanRepaymentResult> task = new Task<>() {
            @Override protected GroupLoanRepaymentResult call() throws Exception {
                return groupLoanService.recordRepayment(selected.getId(), amount, recordedBy, null, null);
            }
        };
        task.setOnSucceeded(e -> {
            statusLabel.setText("");
            Alert alert = new Alert(Alert.AlertType.INFORMATION,
                    "Repayment recorded. Outstanding balance: "
                    + Money.format(task.getValue().groupLoan().getOutstandingBalance()));
            alert.setHeaderText(null);
            alert.showAndWait();
            refresh();
            loadInstallments(selected);
        });
        task.setOnFailed(e -> statusLabel.setText("Could not record repayment: " + task.getException().getMessage()));
        new Thread(task, "group-loan-repayment").start();
    }

    @FXML
    private void onWriteOff() {
        GroupLoan selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }

        TextInputDialog dialog = new TextInputDialog();
        dialog.setTitle("Write Off Group Loan");
        dialog.setHeaderText(selected.getLoanNumber() + " — this permanently closes the loan and recognizes the"
                + " remaining balance as a loss. This cannot be undone.");
        dialog.setContentText("Reason:");
        Optional<String> input = dialog.showAndWait();
        if (input.isEmpty() || input.get().isBlank()) {
            return;
        }

        String writtenOffBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
        runAction("Writing off…", () -> groupLoanService.writeOff(selected.getId(), writtenOffBy, input.get().trim()),
                "Could not write off loan");
    }

    private void runAction(String progress, GroupLoanAction action, String failureMessage) {
        GroupLoan selected = table.getSelectionModel().getSelectedItem();
        statusLabel.setText(progress);
        Task<GroupLoan> task = new Task<>() {
            @Override protected GroupLoan call() throws Exception {
                return action.run();
            }
        };
        task.setOnSucceeded(e -> {
            statusLabel.setText("");
            refresh();
            loadInstallments(selected);
        });
        task.setOnFailed(e -> statusLabel.setText(failureMessage + ": " + task.getException().getMessage()));
        new Thread(task, "group-loan-action").start();
    }

    private long parse(String text) {
        try {
            return text == null || text.isBlank() ? 0 : Money.toMinorUnits(text.trim());
        } catch (RuntimeException e) {
            return 0;
        }
    }

    @FunctionalInterface
    private interface GroupLoanAction {
        GroupLoan run() throws Exception;
    }
}
