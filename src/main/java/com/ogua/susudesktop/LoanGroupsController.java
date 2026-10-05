package com.ogua.susudesktop;

import db.SessionManager;
import java.time.LocalDate;
import java.util.List;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.control.Alert;
import javafx.scene.control.Button;
import javafx.scene.control.ButtonType;
import javafx.scene.control.ComboBox;
import javafx.scene.control.Dialog;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import javafx.scene.control.TextField;
import models.Customer;
import models.LoanGroup;
import models.LoanGroupMember;
import service.CustomerService;
import service.LoanGroupInsightsService;
import service.LoanGroupService;
import support.Money;

/**
 * Loan group roster management: create, add/remove members. Unlike susu
 * groups, there is no activation freeze — members can be added or removed
 * anytime, except while jointly liable on a disbursed, unclosed group loan
 * (surfaced as a validation-error status label, mirroring onReject's pattern).
 */
public class LoanGroupsController {

    @FXML private TableView<LoanGroup> table;
    @FXML private TableColumn<LoanGroup, String> nameColumn;
    @FXML private TableColumn<LoanGroup, String> codeColumn;
    @FXML private TableColumn<LoanGroup, String> membersCountColumn;
    @FXML private TableColumn<LoanGroup, String> activeColumn;
    @FXML private Label statusLabel;

    @FXML private TextField nameField;
    @FXML private TextField codeField;
    @FXML private Label createStatusLabel;

    @FXML private TextField customerSearchField;
    @FXML private ComboBox<Customer> customerCombo;
    @FXML private Label memberStatusLabel;

    @FXML private Button toggleActiveButton;
    @FXML private Button removeMemberButton;
    @FXML private Button enterTransactionButton;
    @FXML private Button historyButton;
    @FXML private Label summaryLabel;

    @FXML private TableView<LoanGroupMember> membersTable;
    @FXML private TableColumn<LoanGroupMember, String> memberNameColumn;
    @FXML private TableColumn<LoanGroupMember, String> memberStatusColumn;
    @FXML private TableColumn<LoanGroupMember, String> memberJoinedColumn;

    private final LoanGroupService loanGroupService = new LoanGroupService();
    private final CustomerService customerService = new CustomerService();
    private final LoanGroupInsightsService insightsService = new LoanGroupInsightsService();

    @FXML
    private void initialize() {
        nameColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("name"));
        codeColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("code"));
        membersCountColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getMembers() != null
                        ? String.valueOf(data.getValue().getMembers().stream().filter(m -> "active".equals(m.getStatus())).count())
                        : "0"));
        activeColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().isActive() ? "Yes" : "No"));

        memberNameColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getCustomer() != null ? data.getValue().getCustomer().fullName() : ""));
        memberStatusColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("status"));
        memberJoinedColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getJoinedAt() != null ? data.getValue().getJoinedAt().toString() : ""));

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

        table.getSelectionModel().selectedItemProperty().addListener((obs, old, selected) -> {
            updateActionButtons(selected);
            loadMembers(selected);
            loadSummary(selected);
        });
        membersTable.getSelectionModel().selectedItemProperty().addListener((obs, old, member) ->
                removeMemberButton.setDisable(member == null));
        updateActionButtons(null);

        refresh();
    }

    private void updateActionButtons(LoanGroup selected) {
        toggleActiveButton.setDisable(selected == null);
        removeMemberButton.setDisable(true);
        enterTransactionButton.setDisable(selected == null || !selected.isActive());
        historyButton.setDisable(selected == null);
    }

    /** Disbursed / paid / outstanding / overdue for the selected group (BuildLoanGroupSummaryAction parity). */
    private void loadSummary(LoanGroup group) {
        if (group == null) {
            summaryLabel.setText("Select a group to see its totals.");
            return;
        }
        Task<LoanGroupInsightsService.Summary> task = new Task<>() {
            @Override
            protected LoanGroupInsightsService.Summary call() throws Exception {
                return insightsService.summary(group.getId());
            }
        };
        task.setOnSucceeded(event -> {
            LoanGroupInsightsService.Summary summary = task.getValue();
            summaryLabel.setText(group.getName() + " — disbursed " + Money.format(summary.totalDisbursed())
                    + " · paid " + Money.format(summary.totalPaid())
                    + " · outstanding " + Money.format(summary.outstanding())
                    + " · overdue " + Money.format(summary.overdue())
                    + " · " + summary.activeMembers() + " member(s), " + summary.activeLoans() + " active loan(s)"
                    + (summary.draftLoans() > 0 ? ", " + summary.draftLoans() + " awaiting activation" : ""));
        });
        task.setOnFailed(event -> summaryLabel.setText("Could not load totals: " + task.getException().getMessage()));
        new Thread(task, "loan-group-summary").start();
    }

    @FXML
    private void onEnterTransaction() {
        LoanGroup group = table.getSelectionModel().getSelectedItem();
        if (group == null) {
            return;
        }
        LocalDate today = LocalDate.now();
        Task<List<LoanGroupInsightsService.SheetRow>> load = new Task<>() {
            @Override
            protected List<LoanGroupInsightsService.SheetRow> call() throws Exception {
                return insightsService.sheet(group.getId(), today);
            }
        };
        load.setOnSucceeded(event -> CollectionSheetDialog.show(group.getName(), today.toString(), load.getValue())
                .filter(entries -> !entries.isEmpty())
                .ifPresent(entries -> postSheet(group, entries)));
        load.setOnFailed(event -> statusLabel.setText("Could not load the sheet: " + load.getException().getMessage()));
        new Thread(load, "loan-group-sheet-load").start();
    }

    private void postSheet(LoanGroup group, List<LoanGroupInsightsService.SheetEntry> entries) {
        var user = SessionManager.getCurrentUser();
        String agentId = user != null ? user.getId() : null;
        String agentName = user != null ? user.getName() : null;
        statusLabel.setText("Posting collection sheet…");
        Task<LoanGroupInsightsService.PostResult> task = new Task<>() {
            @Override
            protected LoanGroupInsightsService.PostResult call() throws Exception {
                return insightsService.post(agentId, agentName, entries);
            }
        };
        task.setOnSucceeded(event -> {
            LoanGroupInsightsService.PostResult result = task.getValue();
            statusLabel.setText("");
            Alert alert = new Alert(Alert.AlertType.INFORMATION, result.repaymentsCount() + " repayment(s) of "
                    + Money.format(result.repaymentsTotal()) + " and " + result.depositsCount() + " deposit(s) of "
                    + Money.format(result.depositsTotal()) + " recorded.");
            alert.setHeaderText("Collection sheet posted");
            alert.showAndWait();
            loadSummary(group);
        });
        task.setOnFailed(event -> statusLabel.setText("Sheet not posted: " + task.getException().getMessage()));
        new Thread(task, "loan-group-sheet-post").start();
    }

    @FXML
    private void onShowHistory() {
        LoanGroup group = table.getSelectionModel().getSelectedItem();
        if (group == null) {
            return;
        }
        Task<List<LoanGroupInsightsService.HistoryEvent>> load = new Task<>() {
            @Override
            protected List<LoanGroupInsightsService.HistoryEvent> call() throws Exception {
                return insightsService.history(group.getId());
            }
        };
        load.setOnSucceeded(event -> {
            TableView<LoanGroupInsightsService.HistoryEvent> history = new TableView<>(FXCollections.observableArrayList(load.getValue()));
            history.getColumns().add(historyColumn("When", e -> e.at().length() > 16 ? e.at().substring(0, 16).replace('T', ' ') : e.at()));
            history.getColumns().add(historyColumn("Activity", LoanGroupInsightsService.HistoryEvent::description));
            history.getColumns().add(historyColumn("Member", e -> e.member() == null ? "—" : e.member()));
            history.getColumns().add(historyColumn("Amount", e -> e.amount() == null ? "—" : Money.format(e.amount())));
            history.setColumnResizePolicy(TableView.CONSTRAINED_RESIZE_POLICY_FLEX_LAST_COLUMN);
            history.setPrefSize(760, 420);

            Dialog<Void> dialog = new Dialog<>();
            dialog.setTitle("Group History — " + group.getName());
            dialog.setHeaderText("Every membership change, loan event, deposit and repayment, newest first.");
            dialog.getDialogPane().getButtonTypes().add(ButtonType.CLOSE);
            dialog.getDialogPane().setContent(history);
            dialog.setResizable(true);
            dialog.showAndWait();
        });
        load.setOnFailed(event -> statusLabel.setText("Could not load history: " + load.getException().getMessage()));
        new Thread(load, "loan-group-history").start();
    }

    private static TableColumn<LoanGroupInsightsService.HistoryEvent, String> historyColumn(
            String title, java.util.function.Function<LoanGroupInsightsService.HistoryEvent, String> value) {
        TableColumn<LoanGroupInsightsService.HistoryEvent, String> column = new TableColumn<>(title);
        column.setCellValueFactory(data -> new SimpleStringProperty(value.apply(data.getValue())));
        return column;
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading loan groups…");
        Task<List<LoanGroup>> task = new Task<>() {
            @Override
            protected List<LoanGroup> call() throws Exception {
                return loanGroupService.findAll();
            }
        };
        task.setOnSucceeded(event -> {
            table.setItems(FXCollections.observableArrayList(task.getValue()));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load loan groups: " + task.getException().getMessage()));
        new Thread(task, "loan-groups-refresh").start();
    }

    private void loadMembers(LoanGroup group) {
        if (group == null) {
            membersTable.setItems(FXCollections.observableArrayList());
            return;
        }
        Task<List<LoanGroupMember>> task = new Task<>() {
            @Override
            protected List<LoanGroupMember> call() throws Exception {
                return loanGroupService.findMembers(group.getId());
            }
        };
        task.setOnSucceeded(event -> membersTable.setItems(FXCollections.observableArrayList(task.getValue())));
        new Thread(task, "loan-group-members-load").start();
    }

    @FXML
    private void onCreateGroup() {
        createStatusLabel.setText("");
        String name = nameField.getText().trim();
        String code = codeField.getText().trim();
        if (name.isEmpty() || code.isEmpty()) {
            createStatusLabel.setText("Enter a name and code.");
            return;
        }

        String createdBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;

        Task<LoanGroup> task = new Task<>() {
            @Override
            protected LoanGroup call() throws Exception {
                return loanGroupService.create(name, code, createdBy);
            }
        };
        task.setOnSucceeded(event -> {
            nameField.clear();
            codeField.clear();
            createStatusLabel.setText("");
            refresh();
        });
        task.setOnFailed(event -> createStatusLabel.setText(
                "Could not create loan group: " + task.getException().getMessage()));
        new Thread(task, "loan-group-create").start();
    }

    @FXML
    private void onFindCustomer() {
        memberStatusLabel.setText("Searching…");
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
            memberStatusLabel.setText("");
        });
        task.setOnFailed(event -> memberStatusLabel.setText(
                "Search failed: " + task.getException().getMessage()));
        new Thread(task, "loan-group-customer-search").start();
    }

    @FXML
    private void onAddMember() {
        memberStatusLabel.setText("");
        LoanGroup selected = table.getSelectionModel().getSelectedItem();
        Customer customer = customerCombo.getValue();
        if (selected == null) {
            memberStatusLabel.setText("Select a loan group first.");
            return;
        }
        if (customer == null) {
            memberStatusLabel.setText("Find and select a customer first.");
            return;
        }

        Task<LoanGroupMember> task = new Task<>() {
            @Override
            protected LoanGroupMember call() throws Exception {
                return loanGroupService.addMember(selected.getId(), customer.getId());
            }
        };
        task.setOnSucceeded(event -> {
            customerSearchField.clear();
            customerCombo.getItems().clear();
            memberStatusLabel.setText("");
            refresh();
            loadMembers(selected);
        });
        task.setOnFailed(event -> memberStatusLabel.setText(
                "Could not add member: " + task.getException().getMessage()));
        new Thread(task, "loan-group-member-add").start();
    }

    @FXML
    private void onRemoveMember() {
        LoanGroupMember selected = membersTable.getSelectionModel().getSelectedItem();
        LoanGroup selectedGroup = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }

        Task<LoanGroupMember> task = new Task<>() {
            @Override
            protected LoanGroupMember call() throws Exception {
                return loanGroupService.removeMember(selected.getId());
            }
        };
        task.setOnSucceeded(event -> {
            statusLabel.setText("");
            refresh();
            loadMembers(selectedGroup);
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not remove member: " + task.getException().getMessage()));
        new Thread(task, "loan-group-member-remove").start();
    }

    @FXML
    private void onToggleActive() {
        LoanGroup selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }

        Task<Void> task = new Task<>() {
            @Override
            protected Void call() throws Exception {
                loanGroupService.setActive(selected.getId(), !selected.isActive());
                return null;
            }
        };
        task.setOnSucceeded(event -> {
            statusLabel.setText("");
            refresh();
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not update loan group: " + task.getException().getMessage()));
        new Thread(task, "loan-group-toggle-active").start();
    }
}
