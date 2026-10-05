package com.ogua.susudesktop;

import db.SessionManager;
import enums.CustomerSegment;
import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.List;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.collections.ObservableList;
import javafx.fxml.FXML;
import javafx.scene.control.Button;
import javafx.scene.control.CheckBox;
import javafx.scene.control.ComboBox;
import javafx.scene.control.DatePicker;
import javafx.scene.control.Label;
import javafx.scene.control.RadioButton;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableRow;
import javafx.scene.control.TableView;
import javafx.scene.control.TextArea;
import javafx.scene.control.TextField;
import javafx.scene.control.ToggleGroup;
import javafx.scene.control.cell.PropertyValueFactory;
import models.Customer;
import models.CustomerBeneficiary;
import models.CustomerFamilyMember;
import models.CustomerIdentification;
import service.CustomerService;
import support.Money;

public class CustomersController {

    @FXML private TextField searchField;
    @FXML private ComboBox<CustomerSegment> segmentCombo;
    @FXML private Label overviewLabel;
    @FXML private TableView<Customer> table;
    @FXML private TableColumn<Customer, String> codeColumn;
    @FXML private TableColumn<Customer, String> nameColumn;
    @FXML private TableColumn<Customer, String> phoneColumn;
    @FXML private TableColumn<Customer, String> statusColumn;

    @FXML private ToggleGroup clientTypeToggle;
    @FXML private RadioButton individualRadio;
    @FXML private RadioButton businessRadio;

    @FXML private TextField firstNameField;
    @FXML private TextField lastNameField;
    @FXML private TextField otherNamesField;
    @FXML private ComboBox<String> genderCombo;
    @FXML private TextField phoneField;
    @FXML private TextField externalIdField;
    @FXML private DatePicker dobPicker;
    @FXML private TextField placeOfBirthField;
    @FXML private TextField nationalityField;
    @FXML private TextField emailField;
    @FXML private TextField occupationField;
    @FXML private TextField jobTitleField;
    @FXML private TextField tinField;
    @FXML private TextField countryOfResidenceField;
    @FXML private TextField residencePermitField;
    @FXML private ComboBox<String> residencyStatusCombo;

    @FXML private TextArea addressField;
    @FXML private TextField cityTownField;
    @FXML private TextField stateRegionField;
    @FXML private TextField countryField;
    @FXML private TextField digitalAddressField;
    @FXML private TextField latitudeField;
    @FXML private TextField longitudeField;

    @FXML private ComboBox<String> maritalStatusCombo;
    @FXML private TextField spouseNameField;
    @FXML private DatePicker spouseDobPicker;
    @FXML private TextField spouseOccupationField;
    @FXML private CheckBox hasPastLoanCheck;
    @FXML private TextField pastLoanInstitutionField;
    @FXML private TextField spouseEmployerNameField;
    @FXML private TextField spouseEmployerAddressField;
    @FXML private TextField spouseEmployerTownField;
    @FXML private TextField spouseEmployerCountyField;
    @FXML private TextField spouseEmployerRegionField;
    @FXML private TextField religionField;

    @FXML private javafx.scene.control.TitledPane businessPane;
    @FXML private TextField businessNameField;
    @FXML private ComboBox<String> businessStructureCombo;
    @FXML private ComboBox<String> businessLineCombo;
    @FXML private DatePicker businessStartDatePicker;
    @FXML private TextField businessPhoneField;
    @FXML private TextField businessTinField;
    @FXML private ComboBox<String> businessIncomeLevelCombo;
    @FXML private TextArea businessAddressField;
    @FXML private TextField businessTownField;
    @FXML private TextField businessCountyField;
    @FXML private TextField businessRegionField;
    @FXML private TextField businessLatitudeField;
    @FXML private TextField businessLongitudeField;

    @FXML private TableView<CustomerIdentification> identificationsTable;
    @FXML private TableColumn<CustomerIdentification, String> idTypeColumn;
    @FXML private TableColumn<CustomerIdentification, String> idNumberColumn;
    @FXML private TableColumn<CustomerIdentification, String> idIssueDateColumn;
    @FXML private TableColumn<CustomerIdentification, String> idExpiryDateColumn;
    @FXML private TableColumn<CustomerIdentification, Boolean> idPrimaryColumn;
    @FXML private ComboBox<String> idTypeEntryCombo;
    @FXML private TextField idNumberEntryField;
    @FXML private DatePicker idIssueDateEntryPicker;
    @FXML private DatePicker idExpiryDateEntryPicker;
    @FXML private TextField idDescriptionEntryField;
    @FXML private CheckBox idPrimaryEntryCheck;

    @FXML private TableView<CustomerBeneficiary> beneficiariesTable;
    @FXML private TableColumn<CustomerBeneficiary, String> beneficiaryNameColumn;
    @FXML private TableColumn<CustomerBeneficiary, String> beneficiaryRelationshipColumn;
    @FXML private TableColumn<CustomerBeneficiary, Number> beneficiaryAmountColumn;
    @FXML private TableColumn<CustomerBeneficiary, String> beneficiaryPhoneColumn;
    @FXML private TextField beneficiaryNameEntryField;
    @FXML private TextField beneficiaryRelationshipEntryField;
    @FXML private TextField beneficiaryAmountEntryField;
    @FXML private TextField beneficiaryPhoneEntryField;
    @FXML private TextField beneficiaryAddressEntryField;
    @FXML private TextField beneficiaryTownEntryField;
    @FXML private TextField beneficiaryCountyEntryField;
    @FXML private TextField beneficiaryStateRegionEntryField;

    @FXML private TableView<CustomerFamilyMember> familyMembersTable;
    @FXML private TableColumn<CustomerFamilyMember, String> familyMemberNameColumn;
    @FXML private TableColumn<CustomerFamilyMember, String> familyMemberRelationshipColumn;
    @FXML private TableColumn<CustomerFamilyMember, String> familyMemberPhoneColumn;
    @FXML private TableColumn<CustomerFamilyMember, String> familyMemberOccupationColumn;
    @FXML private TextField familyMemberNameEntryField;
    @FXML private TextField familyMemberRelationshipEntryField;
    @FXML private TextField familyMemberPhoneEntryField;
    @FXML private TextField familyMemberOccupationEntryField;

    @FXML private TextField kinNameField;
    @FXML private TextField kinPhoneField;
    @FXML private TextField kinRelationshipField;

    @FXML private Label statusLabel;

    private final CustomerService customerService = new CustomerService();
    private final java.util.Map<CustomerSegment, Integer> segmentCounts = new java.util.EnumMap<>(CustomerSegment.class);
    private final ObservableList<CustomerIdentification> identificationItems = FXCollections.observableArrayList();
    private final ObservableList<CustomerBeneficiary> beneficiaryItems = FXCollections.observableArrayList();
    private final ObservableList<CustomerFamilyMember> familyMemberItems = FXCollections.observableArrayList();

    @FXML
    private void initialize() {
        codeColumn.setCellValueFactory(new PropertyValueFactory<>("customerCode"));
        nameColumn.setCellValueFactory(data -> new SimpleStringProperty(data.getValue().fullName()));
        phoneColumn.setCellValueFactory(new PropertyValueFactory<>("phone"));
        statusColumn.setCellValueFactory(data -> new SimpleStringProperty(data.getValue().getStatus().value()));

        segmentCombo.setItems(FXCollections.observableArrayList(CustomerSegment.values()));
        segmentCombo.setValue(CustomerSegment.ALL);
        segmentCombo.setConverter(new javafx.util.StringConverter<>() {
            @Override
            public String toString(CustomerSegment segment) {
                if (segment == null) {
                    return "";
                }
                Integer count = segmentCounts.get(segment);
                return segment.label() + (count != null ? " (" + count + ")" : "");
            }

            @Override
            public CustomerSegment fromString(String string) {
                return null;
            }
        });
        segmentCombo.valueProperty().addListener((obs, old, segment) -> refresh(searchField.getText()));

        genderCombo.setItems(FXCollections.observableArrayList("male", "female"));
        residencyStatusCombo.setItems(FXCollections.observableArrayList("tenant", "property_owner"));
        maritalStatusCombo.setItems(FXCollections.observableArrayList("single", "married", "divorced", "widowed", "separated"));
        businessStructureCombo.setItems(FXCollections.observableArrayList(
                "sole_proprietorship", "partnership", "limited_liability_company", "ngo_cbo", "cooperative", "other"));
        businessLineCombo.setItems(FXCollections.observableArrayList(
                "agriculture", "trading_retail", "manufacturing", "services", "construction",
                "transportation", "hospitality", "technology", "other"));
        businessIncomeLevelCombo.setItems(FXCollections.observableArrayList("low", "medium", "high"));
        idTypeEntryCombo.setItems(FXCollections.observableArrayList(
                "ghana_card", "voters_id", "passport", "drivers_license", "other"));
        idTypeEntryCombo.getSelectionModel().select("ghana_card");

        businessPane.setVisible(false);
        businessPane.setManaged(false);
        clientTypeToggle.selectedToggleProperty().addListener((obs, oldToggle, newToggle) -> {
            boolean isBusiness = newToggle == businessRadio;
            businessPane.setVisible(isBusiness);
            businessPane.setManaged(isBusiness);
        });

        idTypeColumn.setCellValueFactory(new PropertyValueFactory<>("idType"));
        idNumberColumn.setCellValueFactory(new PropertyValueFactory<>("idNumber"));
        idIssueDateColumn.setCellValueFactory(new PropertyValueFactory<>("issueDate"));
        idExpiryDateColumn.setCellValueFactory(new PropertyValueFactory<>("expiryDate"));
        idPrimaryColumn.setCellValueFactory(new PropertyValueFactory<>("isPrimary"));
        identificationsTable.setItems(identificationItems);

        beneficiaryNameColumn.setCellValueFactory(new PropertyValueFactory<>("name"));
        beneficiaryRelationshipColumn.setCellValueFactory(new PropertyValueFactory<>("relationship"));
        beneficiaryAmountColumn.setCellValueFactory(data -> new javafx.beans.property.SimpleDoubleProperty(
                data.getValue().getAmountOfLegacy() / 100.0));
        beneficiaryPhoneColumn.setCellValueFactory(new PropertyValueFactory<>("phone"));
        beneficiariesTable.setItems(beneficiaryItems);

        familyMemberNameColumn.setCellValueFactory(new PropertyValueFactory<>("name"));
        familyMemberRelationshipColumn.setCellValueFactory(new PropertyValueFactory<>("relationship"));
        familyMemberPhoneColumn.setCellValueFactory(new PropertyValueFactory<>("contactPhone"));
        familyMemberOccupationColumn.setCellValueFactory(new PropertyValueFactory<>("occupation"));
        familyMembersTable.setItems(familyMemberItems);

        table.setRowFactory(tableView -> {
            TableRow<Customer> row = new TableRow<>();
            row.setOnMouseClicked(event -> {
                if (event.getClickCount() == 2 && !row.isEmpty()) {
                    CustomerDetailController.show(table.getScene().getWindow(), row.getItem());
                }
            });
            return row;
        });

        refresh(null);
    }

    @FXML
    private void onViewDetail() {
        Customer selected = table.getSelectionModel().getSelectedItem();
        if (selected == null) {
            statusLabel.setText("Select a customer first (or double-click a row).");
            return;
        }
        CustomerDetailController.show(table.getScene().getWindow(), selected);
    }

    @FXML
    private void onSearch() {
        refresh(searchField.getText());
    }

    private void refresh(String query) {
        try {
            List<Customer> results = customerService.search(query, segmentCombo.getValue());
            table.setItems(FXCollections.observableArrayList(results));
            refreshOverview();
        } catch (Exception e) {
            statusLabel.setText("Could not load customers: " + e.getMessage());
        }
    }

    /** Segment counts in the picker plus a totals line — the web list's tabs and stats header. */
    private void refreshOverview() throws java.sql.SQLException {
        CustomerService.Overview overview = customerService.overview();
        segmentCounts.clear();
        segmentCounts.putAll(overview.segments());
        CustomerSegment selected = segmentCombo.getValue();
        // Re-render the combo labels with the fresh counts without re-triggering a reload.
        segmentCombo.setButtonCell(new javafx.scene.control.ListCell<>() {
            @Override
            protected void updateItem(CustomerSegment item, boolean empty) {
                super.updateItem(item, empty);
                setText(empty || item == null ? "" : segmentCombo.getConverter().toString(item));
            }
        });
        segmentCombo.setCellFactory(list -> new javafx.scene.control.ListCell<>() {
            @Override
            protected void updateItem(CustomerSegment item, boolean empty) {
                super.updateItem(item, empty);
                setText(empty || item == null ? "" : segmentCombo.getConverter().toString(item));
            }
        });
        if (selected == null) {
            segmentCombo.setValue(CustomerSegment.ALL);
        }

        int total = overview.segments().getOrDefault(CustomerSegment.ALL, 0);
        overviewLabel.setText(total + " customer(s) · " + overview.newThisMonth() + " registered this month · "
                + overview.segments().getOrDefault(CustomerSegment.ACTIVE, 0) + " active · "
                + overview.segments().getOrDefault(CustomerSegment.WITH_ACTIVE_LOANS, 0) + " with active loans · savings held "
                + Money.format(overview.savingsBalance()));
    }

    @FXML
    private void onAddIdentification() {
        if (idNumberEntryField.getText().isBlank() || idIssueDateEntryPicker.getValue() == null) {
            statusLabel.setText("ID type, ID number, and issue date are required to add an identification.");
            return;
        }
        CustomerIdentification identification = new CustomerIdentification();
        identification.setIdType(idTypeEntryCombo.getValue());
        identification.setIdNumber(idNumberEntryField.getText().trim());
        identification.setIssueDate(idIssueDateEntryPicker.getValue().format(DateTimeFormatter.ISO_LOCAL_DATE));
        identification.setExpiryDate(formatDate(idExpiryDateEntryPicker.getValue()));
        identification.setDescription(emptyToNull(idDescriptionEntryField.getText()));
        identification.setIsPrimary(idPrimaryEntryCheck.isSelected());
        identificationItems.add(identification);

        idNumberEntryField.clear();
        idIssueDateEntryPicker.setValue(null);
        idExpiryDateEntryPicker.setValue(null);
        idDescriptionEntryField.clear();
        idPrimaryEntryCheck.setSelected(false);
        statusLabel.setText("");
    }

    @FXML
    private void onRemoveIdentification() {
        CustomerIdentification selected = identificationsTable.getSelectionModel().getSelectedItem();
        if (selected != null) {
            identificationItems.remove(selected);
        }
    }

    @FXML
    private void onAddBeneficiary() {
        if (beneficiaryNameEntryField.getText().isBlank() || beneficiaryAmountEntryField.getText().isBlank()) {
            statusLabel.setText("Beneficiary name and amount of legacy are required.");
            return;
        }
        long amountOfLegacy;
        try {
            amountOfLegacy = Math.round(Double.parseDouble(beneficiaryAmountEntryField.getText().trim()) * 100);
        } catch (NumberFormatException e) {
            statusLabel.setText("Amount of legacy must be a number.");
            return;
        }

        CustomerBeneficiary beneficiary = new CustomerBeneficiary();
        beneficiary.setName(beneficiaryNameEntryField.getText().trim());
        beneficiary.setRelationship(emptyToNull(beneficiaryRelationshipEntryField.getText()));
        beneficiary.setAmountOfLegacy(amountOfLegacy);
        beneficiary.setPhone(emptyToNull(beneficiaryPhoneEntryField.getText()));
        beneficiary.setAddress(emptyToNull(beneficiaryAddressEntryField.getText()));
        beneficiary.setTown(emptyToNull(beneficiaryTownEntryField.getText()));
        beneficiary.setCounty(emptyToNull(beneficiaryCountyEntryField.getText()));
        beneficiary.setStateRegion(emptyToNull(beneficiaryStateRegionEntryField.getText()));
        beneficiaryItems.add(beneficiary);

        beneficiaryNameEntryField.clear();
        beneficiaryRelationshipEntryField.clear();
        beneficiaryAmountEntryField.clear();
        beneficiaryPhoneEntryField.clear();
        beneficiaryAddressEntryField.clear();
        beneficiaryTownEntryField.clear();
        beneficiaryCountyEntryField.clear();
        beneficiaryStateRegionEntryField.clear();
        statusLabel.setText("");
    }

    @FXML
    private void onRemoveBeneficiary() {
        CustomerBeneficiary selected = beneficiariesTable.getSelectionModel().getSelectedItem();
        if (selected != null) {
            beneficiaryItems.remove(selected);
        }
    }

    @FXML
    private void onAddFamilyMember() {
        if (familyMemberNameEntryField.getText().isBlank()) {
            statusLabel.setText("Family member name is required.");
            return;
        }
        CustomerFamilyMember familyMember = new CustomerFamilyMember();
        familyMember.setName(familyMemberNameEntryField.getText().trim());
        familyMember.setRelationship(emptyToNull(familyMemberRelationshipEntryField.getText()));
        familyMember.setContactPhone(emptyToNull(familyMemberPhoneEntryField.getText()));
        familyMember.setOccupation(emptyToNull(familyMemberOccupationEntryField.getText()));
        familyMemberItems.add(familyMember);

        familyMemberNameEntryField.clear();
        familyMemberRelationshipEntryField.clear();
        familyMemberPhoneEntryField.clear();
        familyMemberOccupationEntryField.clear();
        statusLabel.setText("");
    }

    @FXML
    private void onRemoveFamilyMember() {
        CustomerFamilyMember selected = familyMembersTable.getSelectionModel().getSelectedItem();
        if (selected != null) {
            familyMemberItems.remove(selected);
        }
    }

    @FXML
    private void onRegister() {
        statusLabel.setText("");

        boolean isBusiness = clientTypeToggle.getSelectedToggle() == businessRadio;

        if (firstNameField.getText().isBlank() || lastNameField.getText().isBlank() || phoneField.getText().isBlank()) {
            statusLabel.setText("First name, last name, and phone are required.");
            return;
        }
        if (isBusiness && (businessNameField.getText().isBlank() || businessStructureCombo.getValue() == null
                || businessStartDatePicker.getValue() == null)) {
            statusLabel.setText("Business name, business constitution, and business start date are required for business clients.");
            return;
        }

        Double latitude;
        Double longitude;
        Double businessLatitude;
        Double businessLongitude;
        try {
            latitude = parseNullableDouble(latitudeField.getText());
            longitude = parseNullableDouble(longitudeField.getText());
            businessLatitude = parseNullableDouble(businessLatitudeField.getText());
            businessLongitude = parseNullableDouble(businessLongitudeField.getText());
        } catch (NumberFormatException e) {
            statusLabel.setText("Latitude/longitude must be numbers.");
            return;
        }

        Customer customer = new Customer();
        customer.setClientType(isBusiness ? "business" : "individual");
        customer.setFirstName(firstNameField.getText().trim());
        customer.setLastName(lastNameField.getText().trim());
        customer.setOtherNames(emptyToNull(otherNamesField.getText()));
        customer.setGender(genderCombo.getValue());
        customer.setPhone(phoneField.getText().trim());
        customer.setExternalId(emptyToNull(externalIdField.getText()));
        customer.setDateOfBirth(formatDate(dobPicker.getValue()));
        customer.setPlaceOfBirth(emptyToNull(placeOfBirthField.getText()));
        customer.setNationality(emptyToNull(nationalityField.getText()));
        customer.setEmail(emptyToNull(emailField.getText()));
        customer.setOccupation(emptyToNull(occupationField.getText()));
        customer.setJobTitle(emptyToNull(jobTitleField.getText()));
        customer.setTin(emptyToNull(tinField.getText()));
        customer.setCountryOfResidence(emptyToNull(countryOfResidenceField.getText()));
        customer.setResidencePermit(emptyToNull(residencePermitField.getText()));
        customer.setResidencyStatus(residencyStatusCombo.getValue());

        customer.setAddress(emptyToNull(addressField.getText()));
        customer.setCityTown(emptyToNull(cityTownField.getText()));
        customer.setStateRegion(emptyToNull(stateRegionField.getText()));
        customer.setCountry(emptyToNull(countryField.getText()));
        customer.setDigitalAddress(emptyToNull(digitalAddressField.getText()));
        customer.setLatitude(latitude);
        customer.setLongitude(longitude);

        customer.setMaritalStatus(maritalStatusCombo.getValue());
        customer.setSpouseName(emptyToNull(spouseNameField.getText()));
        customer.setSpouseDateOfBirth(formatDate(spouseDobPicker.getValue()));
        customer.setSpouseOccupation(emptyToNull(spouseOccupationField.getText()));
        customer.setHasPastLoan(hasPastLoanCheck.isSelected());
        customer.setPastLoanInstitution(emptyToNull(pastLoanInstitutionField.getText()));
        customer.setSpouseEmployerName(emptyToNull(spouseEmployerNameField.getText()));
        customer.setSpouseEmployerAddress(emptyToNull(spouseEmployerAddressField.getText()));
        customer.setSpouseEmployerTown(emptyToNull(spouseEmployerTownField.getText()));
        customer.setSpouseEmployerCounty(emptyToNull(spouseEmployerCountyField.getText()));
        customer.setSpouseEmployerRegion(emptyToNull(spouseEmployerRegionField.getText()));
        customer.setReligion(emptyToNull(religionField.getText()));

        if (isBusiness) {
            customer.setBusinessName(businessNameField.getText().trim());
            customer.setBusinessStructure(businessStructureCombo.getValue());
            customer.setBusinessLine(businessLineCombo.getValue());
            customer.setBusinessStartDate(formatDate(businessStartDatePicker.getValue()));
            customer.setBusinessPhone(emptyToNull(businessPhoneField.getText()));
            customer.setBusinessTin(emptyToNull(businessTinField.getText()));
            customer.setBusinessIncomeLevel(businessIncomeLevelCombo.getValue());
            customer.setBusinessAddress(emptyToNull(businessAddressField.getText()));
            customer.setBusinessTown(emptyToNull(businessTownField.getText()));
            customer.setBusinessCounty(emptyToNull(businessCountyField.getText()));
            customer.setBusinessRegion(emptyToNull(businessRegionField.getText()));
            customer.setBusinessLatitude(businessLatitude);
            customer.setBusinessLongitude(businessLongitude);
        }

        customer.setNextOfKinName(emptyToNull(kinNameField.getText()));
        customer.setNextOfKinPhone(emptyToNull(kinPhoneField.getText()));
        customer.setNextOfKinRelationship(emptyToNull(kinRelationshipField.getText()));

        try {
            String registeredBy = SessionManager.getCurrentUser() != null ? SessionManager.getCurrentUser().getId() : null;
            customer.setAssignedAgentId(registeredBy);
            customerService.register(customer, registeredBy, null,
                    new ArrayList<>(identificationItems), new ArrayList<>(beneficiaryItems), new ArrayList<>(familyMemberItems));

            clearForm();
            refresh(searchField.getText());
        } catch (Exception e) {
            statusLabel.setText(friendlyRegisterError(e));
        }
    }

    private void clearForm() {
        individualRadio.setSelected(true);
        firstNameField.clear();
        lastNameField.clear();
        otherNamesField.clear();
        genderCombo.getSelectionModel().clearSelection();
        phoneField.clear();
        externalIdField.clear();
        dobPicker.setValue(null);
        placeOfBirthField.clear();
        nationalityField.clear();
        emailField.clear();
        occupationField.clear();
        jobTitleField.clear();
        tinField.clear();
        countryOfResidenceField.clear();
        residencePermitField.clear();
        residencyStatusCombo.getSelectionModel().clearSelection();

        addressField.clear();
        cityTownField.clear();
        stateRegionField.clear();
        countryField.setText("Ghana");
        digitalAddressField.clear();
        latitudeField.clear();
        longitudeField.clear();

        maritalStatusCombo.getSelectionModel().clearSelection();
        spouseNameField.clear();
        spouseDobPicker.setValue(null);
        spouseOccupationField.clear();
        hasPastLoanCheck.setSelected(false);
        pastLoanInstitutionField.clear();
        spouseEmployerNameField.clear();
        spouseEmployerAddressField.clear();
        spouseEmployerTownField.clear();
        spouseEmployerCountyField.clear();
        spouseEmployerRegionField.clear();
        religionField.clear();

        businessNameField.clear();
        businessStructureCombo.getSelectionModel().clearSelection();
        businessLineCombo.getSelectionModel().clearSelection();
        businessStartDatePicker.setValue(null);
        businessPhoneField.clear();
        businessTinField.clear();
        businessIncomeLevelCombo.getSelectionModel().clearSelection();
        businessAddressField.clear();
        businessTownField.clear();
        businessCountyField.clear();
        businessRegionField.clear();
        businessLatitudeField.clear();
        businessLongitudeField.clear();

        identificationItems.clear();
        beneficiaryItems.clear();
        familyMemberItems.clear();

        kinNameField.clear();
        kinPhoneField.clear();
        kinRelationshipField.clear();
    }

    private String friendlyRegisterError(Exception e) {
        String message = e.getMessage() != null ? e.getMessage() : "";
        if (message.contains("UNIQUE") && message.contains("customers.phone")) {
            return "A customer with this phone number is already registered.";
        }
        return "Could not save customer: " + message;
    }

    private String formatDate(LocalDate date) {
        return date != null ? date.format(DateTimeFormatter.ISO_LOCAL_DATE) : null;
    }

    private Double parseNullableDouble(String value) {
        return (value == null || value.isBlank()) ? null : Double.parseDouble(value.trim());
    }

    private String emptyToNull(String value) {
        return (value == null || value.isBlank()) ? null : value.trim();
    }
}
