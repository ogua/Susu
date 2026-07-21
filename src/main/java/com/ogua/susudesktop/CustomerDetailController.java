package com.ogua.susudesktop;

import enums.LedgerAccountType;
import java.io.IOException;
import java.util.List;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.fxml.FXMLLoader;
import javafx.scene.Parent;
import javafx.scene.Scene;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import javafx.scene.control.TitledPane;
import javafx.scene.control.cell.PropertyValueFactory;
import javafx.stage.Modality;
import javafx.stage.Stage;
import javafx.stage.Window;
import models.Customer;
import models.CustomerBeneficiary;
import models.CustomerFamilyMember;
import models.CustomerIdentification;
import models.LedgerAccount;
import models.LedgerEntryRow;
import models.SavingsAccount;
import service.CustomerService;
import service.ReportService;
import service.SavingsAccountService;
import support.Money;

/**
 * Customer 360 view — profile, full eBanQR-depth KYC details, savings
 * accounts, and per-account transaction history (the desktop mirror of the
 * web admin's customer edit page). Opened as a modal from the Customers
 * screen. Read-only — KYC data can only be edited via the registration
 * screen or the web admin.
 */
public class CustomerDetailController {

    @FXML private Label nameLabel;
    @FXML private Label codeLabel;
    @FXML private Label clientTypeValueLabel;
    @FXML private Label phoneLabel;
    @FXML private Label statusValueLabel;
    @FXML private Label kinLabel;
    @FXML private Label addressLabel;

    @FXML private Label otherNamesValueLabel;
    @FXML private Label genderValueLabel;
    @FXML private Label dobValueLabel;
    @FXML private Label placeOfBirthValueLabel;
    @FXML private Label nationalityValueLabel;
    @FXML private Label emailValueLabel;
    @FXML private Label externalIdValueLabel;
    @FXML private Label occupationValueLabel;
    @FXML private Label jobTitleValueLabel;
    @FXML private Label tinValueLabel;
    @FXML private Label countryOfResidenceValueLabel;
    @FXML private Label residencePermitValueLabel;
    @FXML private Label residencyStatusValueLabel;

    @FXML private Label cityTownValueLabel;
    @FXML private Label stateRegionValueLabel;
    @FXML private Label countryValueLabel;
    @FXML private Label digitalAddressValueLabel;
    @FXML private Label latitudeValueLabel;
    @FXML private Label longitudeValueLabel;

    @FXML private Label maritalStatusValueLabel;
    @FXML private Label spouseNameValueLabel;
    @FXML private Label spouseDobValueLabel;
    @FXML private Label spouseOccupationValueLabel;
    @FXML private Label hasPastLoanValueLabel;
    @FXML private Label pastLoanInstitutionValueLabel;
    @FXML private Label spouseEmployerNameValueLabel;
    @FXML private Label spouseEmployerAddressValueLabel;
    @FXML private Label spouseEmployerTownValueLabel;
    @FXML private Label spouseEmployerCountyValueLabel;
    @FXML private Label spouseEmployerRegionValueLabel;
    @FXML private Label religionValueLabel;

    @FXML private TitledPane businessPane;
    @FXML private Label businessNameValueLabel;
    @FXML private Label businessStructureValueLabel;
    @FXML private Label businessLineValueLabel;
    @FXML private Label businessStartDateValueLabel;
    @FXML private Label businessPhoneValueLabel;
    @FXML private Label businessTinValueLabel;
    @FXML private Label businessIncomeLevelValueLabel;
    @FXML private Label businessAddressValueLabel;
    @FXML private Label businessTownValueLabel;
    @FXML private Label businessCountyValueLabel;
    @FXML private Label businessRegionValueLabel;
    @FXML private Label businessLatitudeValueLabel;
    @FXML private Label businessLongitudeValueLabel;

    @FXML private TableView<CustomerIdentification> identificationsTable;
    @FXML private TableColumn<CustomerIdentification, String> idTypeColumn;
    @FXML private TableColumn<CustomerIdentification, String> idNumberColumn;
    @FXML private TableColumn<CustomerIdentification, String> idIssueDateColumn;
    @FXML private TableColumn<CustomerIdentification, String> idExpiryDateColumn;
    @FXML private TableColumn<CustomerIdentification, String> idPrimaryColumn;

    @FXML private TableView<CustomerBeneficiary> beneficiariesTable;
    @FXML private TableColumn<CustomerBeneficiary, String> beneficiaryNameColumn;
    @FXML private TableColumn<CustomerBeneficiary, String> beneficiaryRelationshipColumn;
    @FXML private TableColumn<CustomerBeneficiary, String> beneficiaryAmountColumn;
    @FXML private TableColumn<CustomerBeneficiary, String> beneficiaryPhoneColumn;

    @FXML private TableView<CustomerFamilyMember> familyMembersTable;
    @FXML private TableColumn<CustomerFamilyMember, String> familyMemberNameColumn;
    @FXML private TableColumn<CustomerFamilyMember, String> familyMemberRelationshipColumn;
    @FXML private TableColumn<CustomerFamilyMember, String> familyMemberPhoneColumn;
    @FXML private TableColumn<CustomerFamilyMember, String> familyMemberOccupationColumn;

    @FXML private TableView<SavingsAccount> accountsTable;
    @FXML private TableColumn<SavingsAccount, String> accountNumberColumn;
    @FXML private TableColumn<SavingsAccount, String> accountStatusColumn;
    @FXML private TableColumn<SavingsAccount, String> accountOpenedColumn;
    @FXML private TableColumn<SavingsAccount, String> accountBalanceColumn;

    @FXML private Label transactionsTitleLabel;
    @FXML private TableView<LedgerEntryRow> transactionsTable;
    @FXML private TableColumn<LedgerEntryRow, String> txnDateColumn;
    @FXML private TableColumn<LedgerEntryRow, String> txnTypeColumn;
    @FXML private TableColumn<LedgerEntryRow, String> txnDescriptionColumn;
    @FXML private TableColumn<LedgerEntryRow, String> txnDebitColumn;
    @FXML private TableColumn<LedgerEntryRow, String> txnCreditColumn;
    @FXML private TableColumn<LedgerEntryRow, String> txnBalanceColumn;
    @FXML private Label statusLabel;

    private final SavingsAccountService accountService = new SavingsAccountService();
    private final CustomerService customerService = new CustomerService();
    private final ReportService reportService = new ReportService();

    public static void show(Window owner, Customer customer) {
        try {
            FXMLLoader loader = new FXMLLoader(
                    CustomerDetailController.class.getResource("customer-detail-view.fxml"));
            Parent view = loader.load();
            CustomerDetailController controller = loader.getController();
            controller.load(customer);

            Stage stage = new Stage();
            stage.setTitle("Customer — " + customer.fullName());
            stage.initModality(Modality.WINDOW_MODAL);
            stage.initOwner(owner);
            Scene scene = new Scene(view, 920, 720);
            scene.getStylesheets().addAll(owner.getScene().getStylesheets());
            stage.setScene(scene);
            stage.show();
        } catch (IOException e) {
            throw new IllegalStateException("Could not open customer detail: " + e.getMessage(), e);
        }
    }

    @FXML
    private void initialize() {
        accountNumberColumn.setCellValueFactory(new PropertyValueFactory<>("accountNumber"));
        accountStatusColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getStatus().value()));
        accountOpenedColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getOpenedAt() != null && data.getValue().getOpenedAt().length() >= 10
                        ? data.getValue().getOpenedAt().substring(0, 10)
                        : "—"));
        accountBalanceColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getBalance())));

        txnDateColumn.setCellValueFactory(new PropertyValueFactory<>("recordedAt"));
        txnTypeColumn.setCellValueFactory(new PropertyValueFactory<>("type"));
        txnDescriptionColumn.setCellValueFactory(new PropertyValueFactory<>("description"));
        txnDebitColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getDebit() > 0 ? Money.format(data.getValue().getDebit()) : ""));
        txnCreditColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getCredit() > 0 ? Money.format(data.getValue().getCredit()) : ""));
        txnBalanceColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getRunningBalance())));

        accountsTable.getSelectionModel().selectedItemProperty()
                .addListener((obs, old, selected) -> loadTransactions(selected));

        idTypeColumn.setCellValueFactory(new PropertyValueFactory<>("idType"));
        idNumberColumn.setCellValueFactory(new PropertyValueFactory<>("idNumber"));
        idIssueDateColumn.setCellValueFactory(new PropertyValueFactory<>("issueDate"));
        idExpiryDateColumn.setCellValueFactory(new PropertyValueFactory<>("expiryDate"));
        idPrimaryColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Boolean.TRUE.equals(data.getValue().getIsPrimary()) ? "Yes" : ""));

        beneficiaryNameColumn.setCellValueFactory(new PropertyValueFactory<>("name"));
        beneficiaryRelationshipColumn.setCellValueFactory(new PropertyValueFactory<>("relationship"));
        beneficiaryAmountColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getAmountOfLegacy())));
        beneficiaryPhoneColumn.setCellValueFactory(new PropertyValueFactory<>("phone"));

        familyMemberNameColumn.setCellValueFactory(new PropertyValueFactory<>("name"));
        familyMemberRelationshipColumn.setCellValueFactory(new PropertyValueFactory<>("relationship"));
        familyMemberPhoneColumn.setCellValueFactory(new PropertyValueFactory<>("contactPhone"));
        familyMemberOccupationColumn.setCellValueFactory(new PropertyValueFactory<>("occupation"));
    }

    private void load(Customer customer) {
        nameLabel.setText(customer.fullName());
        codeLabel.setText(customer.getCustomerCode());
        clientTypeValueLabel.setText(customer.getClientType() != null
                ? "(" + customer.getClientType() + ")" : "");
        statusValueLabel.setText(customer.getStatus().value());
        phoneLabel.setText(customer.getPhone());
        kinLabel.setText(customer.getNextOfKinName() != null
                ? customer.getNextOfKinName()
                + (customer.getNextOfKinPhone() != null ? " (" + customer.getNextOfKinPhone() + ")" : "")
                : "—");
        addressLabel.setText(customer.getAddress() != null ? customer.getAddress() : "—");

        otherNamesValueLabel.setText(text(customer.getOtherNames()));
        genderValueLabel.setText(text(customer.getGender()));
        dobValueLabel.setText(text(customer.getDateOfBirth()));
        placeOfBirthValueLabel.setText(text(customer.getPlaceOfBirth()));
        nationalityValueLabel.setText(text(customer.getNationality()));
        emailValueLabel.setText(text(customer.getEmail()));
        externalIdValueLabel.setText(text(customer.getExternalId()));
        occupationValueLabel.setText(text(customer.getOccupation()));
        jobTitleValueLabel.setText(text(customer.getJobTitle()));
        tinValueLabel.setText(text(customer.getTin()));
        countryOfResidenceValueLabel.setText(text(customer.getCountryOfResidence()));
        residencePermitValueLabel.setText(text(customer.getResidencePermit()));
        residencyStatusValueLabel.setText(text(customer.getResidencyStatus()));

        cityTownValueLabel.setText(text(customer.getCityTown()));
        stateRegionValueLabel.setText(text(customer.getStateRegion()));
        countryValueLabel.setText(text(customer.getCountry()));
        digitalAddressValueLabel.setText(text(customer.getDigitalAddress()));
        latitudeValueLabel.setText(text(customer.getLatitude()));
        longitudeValueLabel.setText(text(customer.getLongitude()));

        maritalStatusValueLabel.setText(text(customer.getMaritalStatus()));
        spouseNameValueLabel.setText(text(customer.getSpouseName()));
        spouseDobValueLabel.setText(text(customer.getSpouseDateOfBirth()));
        spouseOccupationValueLabel.setText(text(customer.getSpouseOccupation()));
        hasPastLoanValueLabel.setText(text(customer.getHasPastLoan()));
        pastLoanInstitutionValueLabel.setText(text(customer.getPastLoanInstitution()));
        spouseEmployerNameValueLabel.setText(text(customer.getSpouseEmployerName()));
        spouseEmployerAddressValueLabel.setText(text(customer.getSpouseEmployerAddress()));
        spouseEmployerTownValueLabel.setText(text(customer.getSpouseEmployerTown()));
        spouseEmployerCountyValueLabel.setText(text(customer.getSpouseEmployerCounty()));
        spouseEmployerRegionValueLabel.setText(text(customer.getSpouseEmployerRegion()));
        religionValueLabel.setText(text(customer.getReligion()));

        boolean isBusiness = "business".equals(customer.getClientType());
        businessPane.setVisible(isBusiness);
        businessPane.setManaged(isBusiness);
        if (isBusiness) {
            businessNameValueLabel.setText(text(customer.getBusinessName()));
            businessStructureValueLabel.setText(text(customer.getBusinessStructure()));
            businessLineValueLabel.setText(text(customer.getBusinessLine()));
            businessStartDateValueLabel.setText(text(customer.getBusinessStartDate()));
            businessPhoneValueLabel.setText(text(customer.getBusinessPhone()));
            businessTinValueLabel.setText(text(customer.getBusinessTin()));
            businessIncomeLevelValueLabel.setText(text(customer.getBusinessIncomeLevel()));
            businessAddressValueLabel.setText(text(customer.getBusinessAddress()));
            businessTownValueLabel.setText(text(customer.getBusinessTown()));
            businessCountyValueLabel.setText(text(customer.getBusinessCounty()));
            businessRegionValueLabel.setText(text(customer.getBusinessRegion()));
            businessLatitudeValueLabel.setText(text(customer.getBusinessLatitude()));
            businessLongitudeValueLabel.setText(text(customer.getBusinessLongitude()));
        }

        loadKycChildRecords(customer);

        Task<List<SavingsAccount>> task = new Task<>() {
            @Override
            protected List<SavingsAccount> call() throws Exception {
                return accountService.findByCustomer(customer.getId());
            }
        };
        task.setOnSucceeded(event -> {
            accountsTable.setItems(FXCollections.observableArrayList(task.getValue()));
            if (!task.getValue().isEmpty()) {
                accountsTable.getSelectionModel().selectFirst();
            }
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load accounts: " + task.getException().getMessage()));
        new Thread(task, "customer-detail-accounts").start();
    }

    private void loadKycChildRecords(Customer customer) {
        Task<CustomerKycChildRecords> task = new Task<>() {
            @Override
            protected CustomerKycChildRecords call() throws Exception {
                return new CustomerKycChildRecords(
                        customerService.findIdentifications(customer.getId()),
                        customerService.findBeneficiaries(customer.getId()),
                        customerService.findFamilyMembers(customer.getId()));
            }
        };
        task.setOnSucceeded(event -> {
            CustomerKycChildRecords records = task.getValue();
            identificationsTable.setItems(FXCollections.observableArrayList(records.identifications()));
            beneficiariesTable.setItems(FXCollections.observableArrayList(records.beneficiaries()));
            familyMembersTable.setItems(FXCollections.observableArrayList(records.familyMembers()));
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load KYC details: " + task.getException().getMessage()));
        new Thread(task, "customer-detail-kyc").start();
    }

    private record CustomerKycChildRecords(
            List<CustomerIdentification> identifications,
            List<CustomerBeneficiary> beneficiaries,
            List<CustomerFamilyMember> familyMembers) {
    }

    private void loadTransactions(SavingsAccount account) {
        if (account == null || account.getLedgerAccountId() == null) {
            transactionsTitleLabel.setText("Transactions");
            transactionsTable.setItems(FXCollections.observableArrayList());
            return;
        }

        // The account's savings-liability ledger account is what its
        // deposits/withdrawals post against; credit-normal running balance
        // matches the customer's passbook view.
        LedgerAccount ledgerAccount = new LedgerAccount();
        ledgerAccount.setId(account.getLedgerAccountId());
        ledgerAccount.setType(LedgerAccountType.fromValue("liability"));

        Task<service.AccountLedgerResult> task = new Task<>() {
            @Override
            protected service.AccountLedgerResult call() throws Exception {
                return reportService.accountLedger(ledgerAccount, null, null);
            }
        };
        task.setOnSucceeded(event -> {
            transactionsTable.setItems(FXCollections.observableArrayList(task.getValue().getRows()));
            transactionsTitleLabel.setText("Transactions — " + account.getAccountNumber());
            statusLabel.setText(task.getValue().getRows().isEmpty() ? "No transactions yet." : "");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load transactions: " + task.getException().getMessage()));
        new Thread(task, "customer-detail-transactions").start();
    }

    private String text(String value) {
        return value == null || value.isBlank() ? "—" : value;
    }

    private String text(Double value) {
        return value == null ? "—" : String.valueOf(value);
    }

    private String text(Boolean value) {
        return value == null ? "—" : (value ? "Yes" : "No");
    }
}
