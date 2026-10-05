package com.ogua.susudesktop;

import java.util.ArrayList;
import java.util.List;
import java.util.Optional;
import javafx.geometry.Insets;
import javafx.scene.control.ButtonBar;
import javafx.scene.control.ButtonType;
import javafx.scene.control.Dialog;
import javafx.scene.control.Label;
import javafx.scene.control.ScrollPane;
import javafx.scene.control.TextField;
import javafx.scene.layout.GridPane;
import javafx.scene.layout.VBox;
import service.LoanGroupInsightsService.SheetEntry;
import service.LoanGroupInsightsService.SheetRow;
import support.Money;

/**
 * "Enter Transaction" for a customer group: one line per active member with
 * what's due pre-filled as the repayment, plus a savings deposit field and
 * running totals — the desktop counterpart of the web collection sheet.
 */
final class CollectionSheetDialog {

    private CollectionSheetDialog() {}

    static Optional<List<SheetEntry>> show(String groupName, String date, List<SheetRow> rows) {
        Dialog<List<SheetEntry>> dialog = new Dialog<>();
        dialog.setTitle("Collection Sheet — " + groupName);
        dialog.setHeaderText("Due on " + date + ". Edit the amounts actually collected, then submit.");
        ButtonType submitType = new ButtonType("Submit Sheet", ButtonBar.ButtonData.OK_DONE);
        dialog.getDialogPane().getButtonTypes().addAll(submitType, ButtonType.CANCEL);
        dialog.setResizable(true);

        GridPane grid = new GridPane();
        grid.setHgap(10);
        grid.setVgap(6);
        grid.setPadding(new Insets(10));
        String[] headers = {"Client", "Loan #", "Balance", "Due", "Overdue", "Repayment (GHS)", "Savings acct.", "Savings bal.", "Deposit (GHS)"};
        for (int col = 0; col < headers.length; col++) {
            Label header = new Label(headers[col]);
            header.setStyle("-fx-font-weight: bold;");
            grid.add(header, col, 0);
        }

        List<TextField> repaymentFields = new ArrayList<>();
        List<TextField> depositFields = new ArrayList<>();
        Label totals = new Label();
        Runnable updateTotals = () -> totals.setText("Repayments " + Money.format(sum(repaymentFields))
                + " · Deposits " + Money.format(sum(depositFields)) + " · " + rows.size() + " member(s)");

        for (int i = 0; i < rows.size(); i++) {
            SheetRow row = rows.get(i);
            int r = i + 1;
            grid.add(new Label(row.customerName()), 0, r);
            grid.add(new Label(row.loanNumber() == null ? "—" : row.loanNumber()), 1, r);
            grid.add(new Label(row.groupLoanId() == null ? "—" : Money.format(row.outstanding())), 2, r);
            grid.add(new Label(row.groupLoanId() == null ? "—" : Money.format(row.amountDue())), 3, r);
            Label overdue = new Label(row.groupLoanId() == null ? "—" : Money.format(row.overdue()));
            if (row.overdue() > 0) {
                overdue.getStyleClass().add("text-danger");
            }
            grid.add(overdue, 4, r);

            TextField repayment = new TextField(row.groupLoanId() != null && row.amountDue() > 0 ? cedis(row.amountDue()) : "");
            repayment.setPrefWidth(110);
            repayment.setDisable(row.groupLoanId() == null);
            repayment.textProperty().addListener((obs, old, value) -> updateTotals.run());
            repaymentFields.add(repayment);
            grid.add(repayment, 5, r);

            grid.add(new Label(row.savingsAccountNumber() == null ? "No account" : row.savingsAccountNumber()), 6, r);
            grid.add(new Label(row.savingsBalance() == null ? "—" : Money.format(row.savingsBalance())), 7, r);
            TextField deposit = new TextField();
            deposit.setPrefWidth(110);
            deposit.setDisable(row.savingsAccountId() == null);
            if (row.contributionAmount() != null) {
                deposit.setPromptText("× " + cedis(row.contributionAmount()));
            }
            deposit.textProperty().addListener((obs, old, value) -> updateTotals.run());
            depositFields.add(deposit);
            grid.add(deposit, 8, r);
        }
        updateTotals.run();

        ScrollPane scroll = new ScrollPane(grid);
        scroll.setFitToWidth(true);
        scroll.setPrefSize(960, 440);
        VBox content = new VBox(10, scroll, totals);
        content.setPadding(new Insets(6));
        dialog.getDialogPane().setContent(content);

        dialog.setResultConverter(buttonType -> {
            if (buttonType != submitType) {
                return null;
            }
            List<SheetEntry> entries = new ArrayList<>();
            for (int i = 0; i < rows.size(); i++) {
                long repayment = parse(repaymentFields.get(i).getText());
                long deposit = parse(depositFields.get(i).getText());
                if (repayment > 0 || deposit > 0) {
                    entries.add(new SheetEntry(rows.get(i), repayment, deposit));
                }
            }
            return entries;
        });

        return dialog.showAndWait();
    }

    private static long sum(List<TextField> fields) {
        return fields.stream().mapToLong(field -> parse(field.getText())).sum();
    }

    private static long parse(String text) {
        try {
            return text == null || text.isBlank() ? 0 : Math.max(0, Money.toMinorUnits(text.trim()));
        } catch (NumberFormatException e) {
            return 0;
        }
    }

    private static String cedis(long minor) {
        return String.format("%.2f", minor / 100.0);
    }
}
