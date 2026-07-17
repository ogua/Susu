package com.ogua.susudesktop;

import enums.CommissionType;
import enums.InterestMethod;
import enums.LoanFrequency;
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
import javafx.scene.control.cell.PropertyValueFactory;
import models.LoanProduct;
import models.SavingsProduct;
import service.LoanProductService;
import service.SavingsProductService;
import support.Money;

/**
 * Savings/loan product management — desktop mirror of the web admin's
 * SavingsProductResource/LoanProductResource. Products are never deleted
 * (existing accounts/loans reference them); they are deactivated instead.
 */
public class ProductsController {

    // Savings products tab
    @FXML private TableView<SavingsProduct> savingsTable;
    @FXML private TableColumn<SavingsProduct, String> spNameColumn;
    @FXML private TableColumn<SavingsProduct, String> spCodeColumn;
    @FXML private TableColumn<SavingsProduct, String> spTypeColumn;
    @FXML private TableColumn<SavingsProduct, String> spContributionColumn;
    @FXML private TableColumn<SavingsProduct, String> spCommissionColumn;
    @FXML private TableColumn<SavingsProduct, String> spActiveColumn;
    @FXML private TextField spNameField;
    @FXML private TextField spCodeField;
    @FXML private ComboBox<String> spTypeCombo;
    @FXML private TextField spContributionField;
    @FXML private TextField spCycleDaysField;
    @FXML private ComboBox<String> spCommissionTypeCombo;
    @FXML private TextField spCommissionValueField;
    @FXML private Button spToggleButton;
    @FXML private Label spStatusLabel;

    // Loan products tab
    @FXML private TableView<LoanProduct> loanTable;
    @FXML private TableColumn<LoanProduct, String> lpNameColumn;
    @FXML private TableColumn<LoanProduct, String> lpCodeColumn;
    @FXML private TableColumn<LoanProduct, String> lpMethodColumn;
    @FXML private TableColumn<LoanProduct, String> lpRateColumn;
    @FXML private TableColumn<LoanProduct, String> lpTermColumn;
    @FXML private TableColumn<LoanProduct, String> lpRangeColumn;
    @FXML private TableColumn<LoanProduct, String> lpActiveColumn;
    @FXML private TextField lpNameField;
    @FXML private TextField lpCodeField;
    @FXML private ComboBox<String> lpMethodCombo;
    @FXML private TextField lpRateField;
    @FXML private TextField lpTermField;
    @FXML private ComboBox<String> lpFrequencyCombo;
    @FXML private TextField lpMinField;
    @FXML private TextField lpMaxField;
    @FXML private Button lpToggleButton;
    @FXML private Label lpStatusLabel;

    private final SavingsProductService savingsProducts = new SavingsProductService();
    private final LoanProductService loanProducts = new LoanProductService();

    @FXML
    private void initialize() {
        spNameColumn.setCellValueFactory(new PropertyValueFactory<>("name"));
        spCodeColumn.setCellValueFactory(new PropertyValueFactory<>("code"));
        spTypeColumn.setCellValueFactory(new PropertyValueFactory<>("type"));
        spContributionColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getContributionAmount())));
        spCommissionColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getCommissionType().value()));
        spActiveColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().isActive() ? "Active" : "Inactive"));
        spTypeCombo.setItems(FXCollections.observableArrayList("daily_susu", "target"));
        spTypeCombo.getSelectionModel().selectFirst();
        spCommissionTypeCombo.setItems(FXCollections.observableArrayList(
                "first_contribution_per_cycle", "flat_per_cycle", "percentage"));
        spCommissionTypeCombo.getSelectionModel().selectFirst();
        savingsTable.getSelectionModel().selectedItemProperty().addListener((obs, old, selected) ->
                spToggleButton.setText(selected != null && selected.isActive() ? "Deactivate" : "Activate"));

        lpNameColumn.setCellValueFactory(new PropertyValueFactory<>("name"));
        lpCodeColumn.setCellValueFactory(new PropertyValueFactory<>("code"));
        lpMethodColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getInterestMethod().value()));
        lpRateColumn.setCellValueFactory(data -> new SimpleStringProperty(
                (data.getValue().getInterestRateBps() / 100.0) + "% / period"));
        lpTermColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getTermPeriodCount() + " " + data.getValue().getRepaymentFrequency().value()));
        lpRangeColumn.setCellValueFactory(data -> new SimpleStringProperty(
                Money.format(data.getValue().getMinAmount()) + " – " + Money.format(data.getValue().getMaxAmount())));
        lpActiveColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().isActive() ? "Active" : "Inactive"));
        lpMethodCombo.setItems(FXCollections.observableArrayList("flat", "reducing_balance"));
        lpMethodCombo.getSelectionModel().selectFirst();
        lpFrequencyCombo.setItems(FXCollections.observableArrayList("weekly", "monthly"));
        lpFrequencyCombo.getSelectionModel().select("monthly");
        loanTable.getSelectionModel().selectedItemProperty().addListener((obs, old, selected) ->
                lpToggleButton.setText(selected != null && selected.isActive() ? "Deactivate" : "Activate"));

        refresh();
    }

    @FXML
    private void refresh() {
        Task<List<SavingsProduct>> savingsTask = new Task<>() {
            @Override
            protected List<SavingsProduct> call() throws Exception {
                return savingsProducts.findAll();
            }
        };
        savingsTask.setOnSucceeded(event ->
                savingsTable.setItems(FXCollections.observableArrayList(savingsTask.getValue())));
        savingsTask.setOnFailed(event ->
                spStatusLabel.setText("Could not load products: " + savingsTask.getException().getMessage()));
        new Thread(savingsTask, "savings-products-refresh").start();

        Task<List<LoanProduct>> loanTask = new Task<>() {
            @Override
            protected List<LoanProduct> call() throws Exception {
                return loanProducts.findAll();
            }
        };
        loanTask.setOnSucceeded(event ->
                loanTable.setItems(FXCollections.observableArrayList(loanTask.getValue())));
        loanTask.setOnFailed(event ->
                lpStatusLabel.setText("Could not load products: " + loanTask.getException().getMessage()));
        new Thread(loanTask, "loan-products-refresh").start();
    }

    @FXML
    private void onCreateSavingsProduct() {
        spStatusLabel.setText("");
        try {
            if (spNameField.getText().isBlank() || spCodeField.getText().isBlank()
                    || spContributionField.getText().isBlank()) {
                spStatusLabel.setText("Name, code, and contribution amount are required.");
                return;
            }

            SavingsProduct product = new SavingsProduct();
            product.setName(spNameField.getText().trim());
            product.setCode(spCodeField.getText().trim());
            product.setType(spTypeCombo.getValue());
            product.setContributionAmount(parseMoney(spContributionField.getText()));
            product.setCycleLengthDays(spCycleDaysField.getText().isBlank()
                    ? 31 : Integer.parseInt(spCycleDaysField.getText().trim()));
            product.setCommissionType(CommissionType.fromValue(spCommissionTypeCombo.getValue()));
            product.setCommissionValue(spCommissionValueField.getText().isBlank()
                    ? 0 : Long.parseLong(spCommissionValueField.getText().trim()));

            savingsProducts.create(product);
            spNameField.clear();
            spCodeField.clear();
            spContributionField.clear();
            spCycleDaysField.clear();
            spCommissionValueField.clear();
            refresh();
        } catch (NumberFormatException e) {
            spStatusLabel.setText("Amounts and day counts must be numbers.");
        } catch (Exception e) {
            spStatusLabel.setText("Could not save product: " + e.getMessage());
        }
    }

    @FXML
    private void onToggleSavingsProduct() {
        SavingsProduct selected = savingsTable.getSelectionModel().getSelectedItem();
        if (selected == null) {
            spStatusLabel.setText("Select a product first.");
            return;
        }
        try {
            savingsProducts.setActive(selected.getId(), !selected.isActive());
            refresh();
        } catch (Exception e) {
            spStatusLabel.setText("Could not update product: " + e.getMessage());
        }
    }

    @FXML
    private void onCreateLoanProduct() {
        lpStatusLabel.setText("");
        try {
            if (lpNameField.getText().isBlank() || lpCodeField.getText().isBlank()
                    || lpRateField.getText().isBlank() || lpTermField.getText().isBlank()
                    || lpMinField.getText().isBlank() || lpMaxField.getText().isBlank()) {
                lpStatusLabel.setText("Name, code, rate, term, and amount range are required.");
                return;
            }

            LoanProduct product = new LoanProduct();
            product.setName(lpNameField.getText().trim());
            product.setCode(lpCodeField.getText().trim());
            product.setInterestMethod(InterestMethod.fromValue(lpMethodCombo.getValue()));
            product.setInterestRateBps(Integer.parseInt(lpRateField.getText().trim()));
            product.setTermPeriodCount(Integer.parseInt(lpTermField.getText().trim()));
            product.setRepaymentFrequency(LoanFrequency.fromValue(lpFrequencyCombo.getValue()));
            product.setOriginationFeeAmount(0);
            product.setPenaltyRateBps(0);
            product.setGracePeriodDays(3);
            product.setMinAmount(parseMoney(lpMinField.getText()));
            product.setMaxAmount(parseMoney(lpMaxField.getText()));

            loanProducts.create(product);
            lpNameField.clear();
            lpCodeField.clear();
            lpRateField.clear();
            lpTermField.clear();
            lpMinField.clear();
            lpMaxField.clear();
            refresh();
        } catch (NumberFormatException e) {
            lpStatusLabel.setText("Rates, terms, and amounts must be numbers.");
        } catch (Exception e) {
            lpStatusLabel.setText("Could not save product: " + e.getMessage());
        }
    }

    @FXML
    private void onToggleLoanProduct() {
        LoanProduct selected = loanTable.getSelectionModel().getSelectedItem();
        if (selected == null) {
            lpStatusLabel.setText("Select a product first.");
            return;
        }
        try {
            loanProducts.setActive(selected.getId(), !selected.isActive());
            refresh();
        } catch (Exception e) {
            lpStatusLabel.setText("Could not update product: " + e.getMessage());
        }
    }

    /** User enters major units ("5.00"); storage is integer minor units. */
    private long parseMoney(String text) {
        return Math.round(Double.parseDouble(text.trim()) * 100);
    }
}
