package com.ogua.susudesktop;

import db.SessionManager;
import java.util.List;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.control.Button;
import javafx.scene.control.ComboBox;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import javafx.scene.control.TextField;
import models.Customer;
import models.LoanGroup;
import models.LoanGroupMember;
import service.CustomerService;
import service.LoanGroupService;

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

    @FXML private TableView<LoanGroupMember> membersTable;
    @FXML private TableColumn<LoanGroupMember, String> memberNameColumn;
    @FXML private TableColumn<LoanGroupMember, String> memberStatusColumn;
    @FXML private TableColumn<LoanGroupMember, String> memberJoinedColumn;

    private final LoanGroupService loanGroupService = new LoanGroupService();
    private final CustomerService customerService = new CustomerService();

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
        });
        membersTable.getSelectionModel().selectedItemProperty().addListener((obs, old, member) ->
                removeMemberButton.setDisable(member == null));
        updateActionButtons(null);

        refresh();
    }

    private void updateActionButtons(LoanGroup selected) {
        toggleActiveButton.setDisable(selected == null);
        removeMemberButton.setDisable(true);
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
