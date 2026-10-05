package com.ogua.susudesktop;

import java.util.ArrayList;
import java.util.List;
import java.util.Optional;
import javafx.event.ActionEvent;
import javafx.geometry.Insets;
import javafx.scene.control.Alert;
import javafx.scene.control.Button;
import javafx.scene.control.ButtonBar;
import javafx.scene.control.ButtonType;
import javafx.scene.control.Dialog;
import javafx.scene.control.Label;
import javafx.scene.control.ScrollPane;
import javafx.scene.control.TextField;
import javafx.scene.control.Tooltip;
import javafx.scene.layout.GridPane;
import javafx.scene.layout.HBox;
import javafx.scene.layout.VBox;
import service.LoanGroupInsightsService.SheetEntry;
import service.LoanGroupInsightsService.SheetRow;
import support.Money;

/**
 * "Enter Transaction" for a customer group: one line per active member showing
 * what's due, a repayment field (empty until filled; "Paid" fills the amount
 * due), a savings deposit field and running totals — the desktop counterpart
 * of the web collection sheet. Rows start empty so an untouched row posts
 * nothing: only customers who actually paid are credited.
 */
final class CollectionSheetDialog {

    private CollectionSheetDialog() {}

    static Optional<List<SheetEntry>> show(String groupName, String date, List<SheetRow> rows) {
        Dialog<List<SheetEntry>> dialog = new Dialog<>();
        dialog.setTitle("Collection Sheet — " + groupName);
        dialog.setHeaderText("Due on " + date + ". Enter only the amounts actually collected, then submit.");
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
        Runnable updateTotals = () -> totals.setText(paying(repaymentFields, depositFields) + " of " + rows.size()
                + " member(s) paying · Repayments " + Money.format(sum(repaymentFields))
                + " · Deposits " + Money.format(sum(depositFields)));

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

            TextField repayment = new TextField();
            repayment.setPrefWidth(110);
            repayment.setPromptText("0.00");
            repayment.setDisable(row.groupLoanId() == null);
            repayment.textProperty().addListener((obs, old, value) -> updateTotals.run());
            repaymentFields.add(repayment);
            Button paidInFull = new Button("Paid");
            paidInFull.setTooltip(new Tooltip("Paid in full: fill " + Money.format(row.amountDue())));
            paidInFull.setDisable(row.groupLoanId() == null || row.amountDue() <= 0);
            paidInFull.setOnAction(event -> repayment.setText(cedis(row.amountDue())));
            grid.add(new HBox(4, repayment, paidInFull), 5, r);

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

        // Confirm before posting, naming the totals and how many members pay.
        Button submit = (Button) dialog.getDialogPane().lookupButton(submitType);
        submit.addEventFilter(ActionEvent.ACTION, event -> {
            long repayments = sum(repaymentFields);
            long deposits = sum(depositFields);
            if (repayments + deposits <= 0) {
                new Alert(Alert.AlertType.WARNING, "Nothing to post — enter at least one amount.").showAndWait();
                event.consume();
                return;
            }
            Alert confirm = new Alert(Alert.AlertType.CONFIRMATION,
                    "Post " + Money.format(repayments) + " in repayments and " + Money.format(deposits)
                            + " in deposits from " + paying(repaymentFields, depositFields) + " of " + rows.size()
                            + " member(s)?\nOnly post money actually collected.");
            confirm.setTitle("Post collection sheet?");
            confirm.setHeaderText(null);
            if (confirm.showAndWait().filter(ButtonType.OK::equals).isEmpty()) {
                event.consume();
            }
        });

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

    private static long paying(List<TextField> repayments, List<TextField> deposits) {
        long count = 0;
        for (int i = 0; i < repayments.size(); i++) {
            if (parse(repayments.get(i).getText()) > 0 || parse(deposits.get(i).getText()) > 0) {
                count++;
            }
        }
        return count;
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
