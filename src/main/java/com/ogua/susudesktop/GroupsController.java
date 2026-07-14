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
import models.Customer;
import models.Group;
import models.GroupMember;
import models.GroupRound;
import service.CustomerService;
import service.GroupService;
import support.Money;

public class GroupsController {

    @FXML private TableView<Group> table;
    @FXML private TableColumn<Group, String> nameColumn;
    @FXML private TableColumn<Group, String> codeColumn;
    @FXML private TableColumn<Group, String> contributionColumn;
    @FXML private TableColumn<Group, String> statusColumn;
    @FXML private Label statusLabel;

    @FXML private TextField nameField;
    @FXML private TextField codeField;
    @FXML private TextField contributionField;
    @FXML private ComboBox<String> frequencyCombo;
    @FXML private Label createStatusLabel;

    @FXML private TextField customerSearchField;
    @FXML private ComboBox<Customer> customerCombo;
    @FXML private TextField rotationPositionField;
    @FXML private Label memberStatusLabel;

    @FXML private Button activateButton;
    @FXML private Button contributeButton;
    @FXML private Button payoutButton;

    private final GroupService groupService = new GroupService();
    private final CustomerService customerService = new CustomerService();

    @FXML
    private void initialize() {
        nameColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("name"));
        codeColumn.setCellValueFactory(new javafx.scene.control.cell.PropertyValueFactory<>("code"));
        contributionColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getContributionAmount())));
        statusColumn.setCellValueFactory(data -> new SimpleStringProperty(data.getValue().getStatus().value()));

        frequencyCombo.setItems(FXCollections.observableArrayList("weekly", "monthly"));
        frequencyCombo.getSelectionModel().select("monthly");

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

        table.getSelectionModel().selectedItemProperty().addListener((obs, old, selected) -> updateActionButtons(selected));
        updateActionButtons(null);

        refresh();
    }

    private void updateActionButtons(Group selected) {
        boolean isDraft = selected != null && selected.getStatus().value().equals("draft");
        boolean isActive = selected != null && selected.getStatus().value().equals("active");
        activateButton.setDisable(!isDraft);
        contributeButton.setDisable(!isActive);
        payoutButton.setDisable(!isActive);
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading groups…");
        Task<List<Group>> task = new Task<>() {
            @Override
            protected List<Group> call() throws Exception {
                return groupService.findAll();
            }
        };
        task.setOnSucceeded(event -> {
            table.setItems(FXCollections.observableArrayList(task.getValue()));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load groups: " + task.getException().getMessage()));
        new Thread(task, "groups-refresh").start();
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

        long contributionAmount;
        try {
            contributionAmount = Money.toMinorUnits(contributionField.getText().trim());
        } catch (Exception e) {
            createStatusLabel.setText("Invalid contribution amount.");
            return;
        }
        String frequency = frequencyCombo.getValue();
        String createdBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;

        Task<Void> task = new Task<>() {
            @Override
            protected Void call() throws Exception {
                createGroup(name, code, contributionAmount, frequency, createdBy);
                return null;
            }
        };
        task.setOnSucceeded(event -> {
            nameField.clear();
            codeField.clear();
            contributionField.clear();
            createStatusLabel.setText("");
            refresh();
        });
        task.setOnFailed(event -> createStatusLabel.setText(
                "Could not create group: " + task.getException().getMessage()));
        new Thread(task, "group-create").start();
    }

    private void createGroup(String name, String code, long contributionAmount, String frequency, String createdBy)
            throws java.sql.SQLException {
        try (var conn = db.DatabaseConnection.getConnection()) {
            String id = java.util.UUID.randomUUID().toString();
            String now = java.time.Instant.now().toString();
            try (var ps = conn.prepareStatement(
                    "INSERT INTO groups_table (id, created_by, name, code, contribution_amount, frequency, status,"
                    + " created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)")) {
                ps.setString(1, id);
                ps.setString(2, createdBy);
                ps.setString(3, name);
                ps.setString(4, code);
                ps.setLong(5, contributionAmount);
                ps.setString(6, frequency);
                ps.setString(7, "draft");
                ps.setString(8, now);
                ps.setString(9, now);
                ps.executeUpdate();
            }
        }
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
        new Thread(task, "group-customer-search").start();
    }

    @FXML
    private void onAddMember() {
        memberStatusLabel.setText("");
        Group selected = table.getSelectionModel().getSelectedItem();
        Customer customer = customerCombo.getValue();
        if (selected == null) {
            memberStatusLabel.setText("Select a group first.");
            return;
        }
        if (customer == null) {
            memberStatusLabel.setText("Find and select a customer first.");
            return;
        }

        int rotationPosition;
        try {
            rotationPosition = Integer.parseInt(rotationPositionField.getText().trim());
        } catch (Exception e) {
            memberStatusLabel.setText("Invalid rotation position.");
            return;
        }

        Task<Void> task = new Task<>() {
            @Override
            protected Void call() throws Exception {
                groupService.addMember(selected.getId(), customer.getId(), rotationPosition);
                return null;
            }
        };
        task.setOnSucceeded(event -> {
            customerSearchField.clear();
            customerCombo.getItems().clear();
            rotationPositionField.clear();
            memberStatusLabel.setText("");
            refresh();
        });
        task.setOnFailed(event -> memberStatusLabel.setText(
                "Could not add member: " + task.getException().getMessage()));
        new Thread(task, "group-member-add").start();
    }

    @FXML
    private void onActivate() {
        Group selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }
        statusLabel.setText("Activating…");

        Task<Void> task = new Task<>() {
            @Override
            protected Void call() throws Exception {
                groupService.activate(selected.getId());
                return null;
            }
        };
        task.setOnSucceeded(event -> {
            statusLabel.setText("");
            refresh();
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not activate group: " + task.getException().getMessage()));
        new Thread(task, "group-activate").start();
    }

    @FXML
    private void onContribute() {
        Group selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }

        Task<List<GroupMember>> loadTask = new Task<>() {
            @Override
            protected List<GroupMember> call() throws Exception {
                return groupService.findMembers(selected.getId());
            }
        };
        loadTask.setOnSucceeded(loadEvent -> {
            List<GroupMember> members = loadTask.getValue();
            if (members.isEmpty()) {
                statusLabel.setText("This group has no members.");
                return;
            }

            javafx.scene.control.ChoiceDialog<GroupMember> dialog = new javafx.scene.control.ChoiceDialog<>(members.get(0), members);
            dialog.setTitle("Record Contribution");
            dialog.setHeaderText(selected.getName());
            dialog.setContentText("Member:");
            dialog.getItems().setAll(members);
            dialog.setSelectedItem(members.get(0));

            Optional<GroupMember> chosen = dialog.showAndWait();
            if (chosen.isEmpty()) {
                return;
            }

            String agentId = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
            String agentName = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getName() : "Agent";
            statusLabel.setText("Recording contribution…");

            Task<Void> task = new Task<>() {
                @Override
                protected Void call() throws Exception {
                    groupService.recordContribution(agentId, agentName, chosen.get().getId(), null);
                    return null;
                }
            };
            task.setOnSucceeded(event -> {
                statusLabel.setText("");
                refresh();
            });
            task.setOnFailed(event -> statusLabel.setText(
                    "Could not record contribution: " + task.getException().getMessage()));
            new Thread(task, "group-contribute").start();
        });
        new Thread(loadTask, "group-members-load").start();
    }

    @FXML
    private void onPayout() {
        Group selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            return;
        }

        Task<GroupRound> loadTask = new Task<>() {
            @Override
            protected GroupRound call() throws Exception {
                return groupService.currentRound(selected.getId());
            }
        };
        loadTask.setOnSucceeded(loadEvent -> {
            GroupRound round = loadTask.getValue();
            if (round == null) {
                statusLabel.setText("No round is currently open for this group.");
                return;
            }

            boolean fullyCollected = round.getTotalCollected() >= round.getTotalExpected();
            boolean override = false;
            if (!fullyCollected) {
                Alert confirm = new Alert(Alert.AlertType.CONFIRMATION,
                        "Round " + round.getRoundNumber() + " has only collected "
                        + Money.format(round.getTotalCollected()) + " of " + Money.format(round.getTotalExpected())
                        + ". Pay out early anyway?");
                Optional<javafx.scene.control.ButtonType> result = confirm.showAndWait();
                if (result.isEmpty() || result.get() != javafx.scene.control.ButtonType.OK) {
                    return;
                }
                override = true;
            }

            boolean finalOverride = override;
            String paidBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
            statusLabel.setText("Paying out round…");

            Task<Void> task = new Task<>() {
                @Override
                protected Void call() throws Exception {
                    groupService.payoutRound(round.getId(), paidBy, finalOverride);
                    return null;
                }
            };
            task.setOnSucceeded(event -> {
                statusLabel.setText("");

                Alert alert = new Alert(Alert.AlertType.INFORMATION, "Round paid out.");
                alert.setHeaderText(null);
                alert.showAndWait();

                refresh();
            });
            task.setOnFailed(event -> statusLabel.setText(
                    "Could not pay out round: " + task.getException().getMessage()));
            new Thread(task, "group-payout").start();
        });
        new Thread(loadTask, "group-round-load").start();
    }
}
