package com.ogua.susudesktop;

import enums.AccountStatus;
import java.util.List;
import java.util.Optional;
import javafx.collections.FXCollections;
import javafx.geometry.Insets;
import javafx.scene.control.ButtonBar;
import javafx.scene.control.ButtonType;
import javafx.scene.control.ComboBox;
import javafx.scene.control.Dialog;
import javafx.scene.control.Label;
import javafx.scene.control.TextArea;
import javafx.scene.control.TextField;
import javafx.scene.layout.GridPane;
import javafx.util.StringConverter;
import models.SavingsAccount;
import support.Money;

/**
 * Dialogs that need a borrower's savings account: picking where a group loan
 * deposit is credited, and a write-off that can draw savings down first.
 * Only active accounts are offered, matching the web UI.
 */
final class SavingsAccountDialogs {

    record WriteOffInput(String reason, String savingsAccountId, long savingsAmountApplied) {}

    private SavingsAccountDialogs() {}

    static Optional<SavingsAccount> pickAccount(String header, List<SavingsAccount> accounts) {
        Dialog<SavingsAccount> dialog = new Dialog<>();
        dialog.setTitle("Record Security Deposit");
        dialog.setHeaderText(header);

        ButtonType recordButtonType = new ButtonType("Record Deposit", ButtonBar.ButtonData.OK_DONE);
        dialog.getDialogPane().getButtonTypes().addAll(recordButtonType, ButtonType.CANCEL);

        ComboBox<SavingsAccount> accountCombo = accountCombo(accounts);
        accountCombo.getSelectionModel().selectFirst();

        GridPane grid = grid();
        grid.add(new Label("Deposit into:"), 0, 0);
        grid.add(accountCombo, 1, 0);
        dialog.getDialogPane().setContent(grid);

        dialog.setResultConverter(buttonType -> buttonType == recordButtonType ? accountCombo.getValue() : null);

        return dialog.showAndWait();
    }

    static Optional<WriteOffInput> writeOff(String title, String header, List<SavingsAccount> accounts) {
        Dialog<WriteOffInput> dialog = new Dialog<>();
        dialog.setTitle(title);
        dialog.setHeaderText(header);

        ButtonType writeOffButtonType = new ButtonType("Write Off", ButtonBar.ButtonData.OK_DONE);
        dialog.getDialogPane().getButtonTypes().addAll(writeOffButtonType, ButtonType.CANCEL);

        List<SavingsAccount> active = active(accounts);
        long totalSavings = active.stream().mapToLong(SavingsAccount::getBalance).sum();

        ComboBox<SavingsAccount> accountCombo = accountCombo(active);
        accountCombo.setPromptText("None — book the full balance as a loss");
        TextField amountField = new TextField();
        amountField.setPromptText("0.00");
        amountField.disableProperty().bind(accountCombo.valueProperty().isNull());
        TextArea reasonArea = new TextArea();
        reasonArea.setPrefRowCount(3);
        reasonArea.setPromptText("Reason");

        GridPane grid = grid();
        grid.add(new Label("Total savings balance:"), 0, 0);
        grid.add(new Label(Money.format(totalSavings)), 1, 0);
        grid.add(new Label("Apply from savings account:"), 0, 1);
        grid.add(accountCombo, 1, 1);
        grid.add(new Label("Amount to apply (GHS):"), 0, 2);
        grid.add(amountField, 1, 2);
        grid.add(new Label("Reason:"), 0, 3);
        grid.add(reasonArea, 1, 3);
        dialog.getDialogPane().setContent(grid);

        dialog.setResultConverter(buttonType -> {
            if (buttonType != writeOffButtonType) {
                return null;
            }
            String reason = reasonArea.getText().trim();
            if (reason.isBlank()) {
                return null;
            }
            SavingsAccount account = accountCombo.getValue();
            if (account == null || amountField.getText().isBlank()) {
                return new WriteOffInput(reason, null, 0);
            }
            try {
                long amount = Money.toMinorUnits(amountField.getText().trim());
                return amount > 0 ? new WriteOffInput(reason, account.getId(), amount) : new WriteOffInput(reason, null, 0);
            } catch (RuntimeException e) {
                return null;
            }
        });

        return dialog.showAndWait();
    }

    static List<SavingsAccount> active(List<SavingsAccount> accounts) {
        return accounts.stream().filter(a -> a.getStatus() == AccountStatus.ACTIVE).toList();
    }

    private static ComboBox<SavingsAccount> accountCombo(List<SavingsAccount> accounts) {
        ComboBox<SavingsAccount> combo = new ComboBox<>(FXCollections.observableArrayList(accounts));
        combo.setConverter(new StringConverter<>() {
            @Override public String toString(SavingsAccount account) {
                return account == null ? "" : account.getAccountNumber() + " — " + Money.format(account.getBalance());
            }
            @Override public SavingsAccount fromString(String string) { return null; }
        });
        return combo;
    }

    private static GridPane grid() {
        GridPane grid = new GridPane();
        grid.setHgap(8);
        grid.setVgap(8);
        grid.setPadding(new Insets(10));
        return grid;
    }
}
