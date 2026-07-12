package com.ogua.susudesktop;

import db.SessionManager;
import java.time.format.DateTimeFormatter;
import java.util.List;
import javafx.collections.FXCollections;
import javafx.fxml.FXML;
import javafx.scene.control.Button;
import javafx.scene.control.ComboBox;
import javafx.scene.control.DatePicker;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import javafx.scene.control.TextArea;
import javafx.scene.control.TextField;
import javafx.scene.control.cell.PropertyValueFactory;
import models.Customer;
import service.CustomerService;

public class CustomersController {

    @FXML private TextField searchField;
    @FXML private TableView<Customer> table;
    @FXML private TableColumn<Customer, String> codeColumn;
    @FXML private TableColumn<Customer, String> nameColumn;
    @FXML private TableColumn<Customer, String> phoneColumn;
    @FXML private TableColumn<Customer, String> statusColumn;

    @FXML private TextField firstNameField;
    @FXML private TextField lastNameField;
    @FXML private TextField phoneField;
    @FXML private ComboBox<String> genderCombo;
    @FXML private DatePicker dobPicker;
    @FXML private ComboBox<String> idTypeCombo;
    @FXML private TextField idNumberField;
    @FXML private TextField kinNameField;
    @FXML private TextField kinPhoneField;
    @FXML private TextField kinRelationshipField;
    @FXML private TextArea addressField;
    @FXML private javafx.scene.control.Label statusLabel;

    private final CustomerService customerService = new CustomerService();

    @FXML
    private void initialize() {
        codeColumn.setCellValueFactory(new PropertyValueFactory<>("customerCode"));
        nameColumn.setCellValueFactory(data -> new javafx.beans.property.SimpleStringProperty(
                data.getValue().fullName()));
        phoneColumn.setCellValueFactory(new PropertyValueFactory<>("phone"));
        statusColumn.setCellValueFactory(data -> new javafx.beans.property.SimpleStringProperty(
                data.getValue().getStatus().value()));

        genderCombo.setItems(FXCollections.observableArrayList("male", "female"));
        idTypeCombo.setItems(FXCollections.observableArrayList("ghana_card", "voters_id", "passport", "drivers_license"));
        idTypeCombo.getSelectionModel().select("ghana_card");

        refresh(null);
    }

    @FXML
    private void onSearch() {
        refresh(searchField.getText());
    }

    private void refresh(String query) {
        try {
            List<Customer> results = customerService.search(query);
            table.setItems(FXCollections.observableArrayList(results));
        } catch (Exception e) {
            statusLabel.setText("Could not load customers: " + e.getMessage());
        }
    }

    @FXML
    private void onRegister() {
        statusLabel.setText("");

        if (firstNameField.getText().isBlank() || lastNameField.getText().isBlank() || phoneField.getText().isBlank()) {
            statusLabel.setText("First name, last name, and phone are required.");
            return;
        }

        Customer customer = new Customer();
        customer.setFirstName(firstNameField.getText().trim());
        customer.setLastName(lastNameField.getText().trim());
        customer.setPhone(phoneField.getText().trim());
        customer.setGender(genderCombo.getValue());
        if (dobPicker.getValue() != null) {
            customer.setDateOfBirth(dobPicker.getValue().format(DateTimeFormatter.ISO_LOCAL_DATE));
        }
        customer.setIdType(idTypeCombo.getValue());
        customer.setIdNumber(emptyToNull(idNumberField.getText()));
        customer.setNextOfKinName(emptyToNull(kinNameField.getText()));
        customer.setNextOfKinPhone(emptyToNull(kinPhoneField.getText()));
        customer.setNextOfKinRelationship(emptyToNull(kinRelationshipField.getText()));
        customer.setAddress(emptyToNull(addressField.getText()));

        try {
            String registeredBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
            customerService.register(customer, registeredBy, null);

            firstNameField.clear();
            lastNameField.clear();
            phoneField.clear();
            genderCombo.getSelectionModel().clearSelection();
            dobPicker.setValue(null);
            idNumberField.clear();
            kinNameField.clear();
            kinPhoneField.clear();
            kinRelationshipField.clear();
            addressField.clear();

            refresh(searchField.getText());
        } catch (Exception e) {
            statusLabel.setText("Could not save customer: " + e.getMessage());
        }
    }

    private String emptyToNull(String value) {
        return (value == null || value.isBlank()) ? null : value.trim();
    }
}
