package com.ogua.susudesktop;

import enums.InterestMethod;
import enums.LoanFrequency;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.List;
import java.util.Optional;
import java.util.function.Function;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.geometry.Insets;
import javafx.scene.Node;
import javafx.scene.control.Button;
import javafx.scene.control.ButtonBar;
import javafx.scene.control.ButtonType;
import javafx.scene.control.ComboBox;
import javafx.scene.control.DatePicker;
import javafx.scene.control.Dialog;
import javafx.scene.control.Label;
import javafx.scene.control.ScrollPane;
import javafx.scene.control.Tab;
import javafx.scene.control.TabPane;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import javafx.scene.control.TextArea;
import javafx.scene.control.TextField;
import javafx.scene.layout.GridPane;
import javafx.scene.layout.HBox;
import javafx.scene.layout.VBox;
import models.Loan;
import models.LoanInstallment;
import models.LoanProduct;
import service.LoanApplicationDetails;
import service.ScheduleGenerator;
import service.ScheduledInstallment;
import support.Money;

/**
 * The loan screens' eBanQR-style dialogs: application details (Terms /
 * Charges / Collateral / Guarantors / Schedule), the loan details view, the
 * schedule recalculator and the what-if calculator. Mirrors the web wizard;
 * all money is typed in GHS and stored in pesewas.
 */
final class LoanDialogs {

    record RecalculateInput(LocalDate firstDueDate, String reason) {}

    private LoanDialogs() {}

    // ------------------------------------------------------- application details

    /**
     * Collects the optional application details on top of {@code product}'s
     * defaults. Settings only become overrides where they differ from the product.
     */
    static Optional<LoanApplicationDetails> applicationDetails(LoanProduct product, long principal,
                                                               LoanApplicationDetails current) {
        Dialog<LoanApplicationDetails> dialog = new Dialog<>();
        dialog.setTitle("Loan Application Details");
        dialog.setHeaderText(product.getName() + " — adjust terms, add charges, collateral and guarantors.");
        ButtonType saveType = new ButtonType("Use These Details", ButtonBar.ButtonData.OK_DONE);
        dialog.getDialogPane().getButtonTypes().addAll(saveType, ButtonType.CANCEL);
        dialog.setResizable(true);

        // Terms
        TextField termField = new TextField(String.valueOf(or(current.termPeriodCount(), product.getTermPeriodCount())));
        ComboBox<LoanFrequency> frequencyCombo = new ComboBox<>(FXCollections.observableArrayList(LoanFrequency.values()));
        frequencyCombo.setValue(or(current.repaymentFrequency(), product.getRepaymentFrequency()));
        TextField rateField = new TextField(percent(or(current.interestRateBps(), product.getInterestRateBps())));
        ComboBox<InterestMethod> methodCombo = new ComboBox<>(FXCollections.observableArrayList(InterestMethod.values()));
        methodCombo.setValue(or(current.interestMethod(), product.getInterestMethod()));
        TextField graceField = new TextField(String.valueOf(or(current.gracePeriodDays(), product.getGracePeriodDays())));
        DatePicker firstDatePicker = new DatePicker(current.firstRepaymentDate());
        firstDatePicker.setPromptText("Default: one period after disbursement");
        TextField purposeField = new TextField(current.purpose() == null ? "" : current.purpose());

        GridPane terms = grid();
        addRow(terms, 0, "Number of repayments:", termField);
        addRow(terms, 1, "Repayment every:", frequencyCombo);
        addRow(terms, 2, "Interest rate (% per period):", rateField);
        addRow(terms, 3, "Interest method:", methodCombo);
        addRow(terms, 4, "Grace period (days):", graceField);
        addRow(terms, 5, "First repayment date:", firstDatePicker);
        addRow(terms, 6, "Loan purpose:", purposeField);

        // Charges / collateral / guarantors: one row of fields per item.
        RowEditor charges = new RowEditor(List.of("Charge name", "Amount (GHS)"), "Add charge");
        List<LoanApplicationDetails.Charge> startingCharges = current.charges() != null ? current.charges()
                : (product.getOriginationFeeAmount() > 0
                        ? List.of(new LoanApplicationDetails.Charge("Processing fee", product.getOriginationFeeAmount()))
                        : List.of());
        startingCharges.forEach(charge -> charges.addRow(charge.name(), cedis(charge.amount())));

        RowEditor collaterals = new RowEditor(List.of("Type", "Description", "Value (GHS)", "Serial / ref."), "Add collateral");
        current.collaterals().forEach(collateral -> collaterals.addRow(collateral.type(), collateral.description(),
                cedis(collateral.estimatedValue()), nullToEmpty(collateral.serialNumber())));

        RowEditor guarantors = new RowEditor(List.of("Name", "Phone", "Relationship", "Guaranteed (GHS)"), "Add guarantor");
        current.guarantors().forEach(guarantor -> guarantors.addRow(guarantor.name(), nullToEmpty(guarantor.phone()),
                nullToEmpty(guarantor.relationship()), guarantor.guaranteedAmount() == null ? "" : cedis(guarantor.guaranteedAmount())));

        // Schedule preview, rebuilt on demand from the current inputs.
        VBox schedulePane = new VBox(8);
        Button previewButton = new Button("Preview Schedule");
        previewButton.getStyleClass().add("button-secondary");
        VBox scheduleHolder = new VBox();
        previewButton.setOnAction(event -> {
            try {
                long charged = charges.rows().stream().mapToLong(row -> money(row.get(1))).sum();
                scheduleHolder.getChildren().setAll(scheduleTable(principal, (int) Math.round(Double.parseDouble(rateField.getText().trim()) * 100),
                        Integer.parseInt(termField.getText().trim()), methodCombo.getValue(), frequencyCombo.getValue(),
                        LocalDate.now(), firstDatePicker.getValue(), charged));
            } catch (Exception e) {
                scheduleHolder.getChildren().setAll(new Label("Check the terms: " + e.getMessage()));
            }
        });
        schedulePane.getChildren().addAll(previewButton, scheduleHolder);
        schedulePane.setPadding(new Insets(10));

        TabPane tabs = new TabPane(
                tab("Terms", terms),
                tab("Charges", charges.node()),
                tab("Collateral", collaterals.node()),
                tab("Guarantors", guarantors.node()),
                tab("Repayment Schedule", schedulePane));
        tabs.setPrefSize(720, 420);
        dialog.getDialogPane().setContent(tabs);

        Label error = new Label();
        error.getStyleClass().add("text-danger");
        dialog.getDialogPane().lookupButton(saveType).addEventFilter(javafx.event.ActionEvent.ACTION, event -> {
            try {
                build(product, termField, frequencyCombo, rateField, methodCombo, graceField, firstDatePicker, purposeField,
                        charges, collaterals, guarantors);
            } catch (Exception e) {
                new javafx.scene.control.Alert(javafx.scene.control.Alert.AlertType.ERROR, e.getMessage()).showAndWait();
                event.consume();
            }
        });

        dialog.setResultConverter(buttonType -> buttonType == saveType
                ? build(product, termField, frequencyCombo, rateField, methodCombo, graceField, firstDatePicker, purposeField,
                        charges, collaterals, guarantors)
                : null);

        return dialog.showAndWait();
    }

    private static LoanApplicationDetails build(LoanProduct product, TextField termField, ComboBox<LoanFrequency> frequencyCombo,
                                                TextField rateField, ComboBox<InterestMethod> methodCombo, TextField graceField,
                                                DatePicker firstDatePicker, TextField purposeField, RowEditor charges,
                                                RowEditor collaterals, RowEditor guarantors) {
        int term = Integer.parseInt(termField.getText().trim());
        int rateBps = (int) Math.round(Double.parseDouble(rateField.getText().trim()) * 100);
        int grace = Integer.parseInt(graceField.getText().trim());
        if (term < 1 || term > 520) {
            throw new IllegalArgumentException("Number of repayments must be between 1 and 520.");
        }
        if (rateBps < 0 || rateBps > 10_000) {
            throw new IllegalArgumentException("Interest rate must be between 0% and 100% per period.");
        }
        if (firstDatePicker.getValue() != null && firstDatePicker.getValue().isBefore(LocalDate.now())) {
            throw new IllegalArgumentException("The first repayment date cannot be in the past.");
        }

        List<LoanApplicationDetails.Charge> chargeList = new ArrayList<>();
        for (List<String> row : charges.rows()) {
            require(row.get(0), "Every charge needs a name.");
            chargeList.add(new LoanApplicationDetails.Charge(row.get(0), money(row.get(1))));
        }
        List<LoanApplicationDetails.Collateral> collateralList = new ArrayList<>();
        for (List<String> row : collaterals.rows()) {
            require(row.get(0), "Every collateral item needs a type.");
            require(row.get(1), "Every collateral item needs a description.");
            collateralList.add(new LoanApplicationDetails.Collateral(row.get(0), row.get(1), money(row.get(2)), blankToNull(row.get(3)), null));
        }
        List<LoanApplicationDetails.Guarantor> guarantorList = new ArrayList<>();
        for (List<String> row : guarantors.rows()) {
            require(row.get(0), "Every guarantor needs a name.");
            guarantorList.add(new LoanApplicationDetails.Guarantor(null, row.get(0), blankToNull(row.get(1)), blankToNull(row.get(2)),
                    null, row.get(3).isBlank() ? null : money(row.get(3))));
        }

        long productFee = product.getOriginationFeeAmount();
        boolean chargesChanged = !(chargeList.size() == (productFee > 0 ? 1 : 0)
                && (chargeList.isEmpty() || chargeList.get(0).amount() == productFee));

        return new LoanApplicationDetails(
                term != product.getTermPeriodCount() ? term : null,
                frequencyCombo.getValue() != product.getRepaymentFrequency() ? frequencyCombo.getValue() : null,
                rateBps != product.getInterestRateBps() ? rateBps : null,
                methodCombo.getValue() != product.getInterestMethod() ? methodCombo.getValue() : null,
                grace != product.getGracePeriodDays() ? grace : null,
                firstDatePicker.getValue(),
                blankToNull(purposeField.getText()),
                chargesChanged ? chargeList : null,
                collateralList,
                guarantorList);
    }

    /** One-line summary for the apply form. */
    static String summary(LoanApplicationDetails details) {
        List<String> parts = new ArrayList<>();
        if (details.termPeriodCount() != null || details.interestRateBps() != null || details.repaymentFrequency() != null) {
            parts.add("custom terms");
        }
        if (details.firstRepaymentDate() != null) {
            parts.add("first repayment " + details.firstRepaymentDate());
        }
        if (details.charges() != null) {
            parts.add(details.charges().size() + " charge(s)");
        }
        if (!details.collaterals().isEmpty()) {
            parts.add(details.collaterals().size() + " collateral item(s)");
        }
        if (!details.guarantors().isEmpty()) {
            parts.add(details.guarantors().size() + " guarantor(s)");
        }
        return parts.isEmpty() ? "Product defaults" : String.join(" · ", parts);
    }

    // ----------------------------------------------------------- loan details

    static void showLoanDetails(Loan loan, List<LoanInstallment> installments, List<LoanApplicationDetails.Charge> charges,
                                List<LoanApplicationDetails.Collateral> collaterals, List<LoanApplicationDetails.Guarantor> guarantors) {
        Dialog<Void> dialog = new Dialog<>();
        dialog.setTitle("Loan " + loan.getLoanNumber());
        dialog.setHeaderText((loan.getCustomer() != null ? loan.getCustomer().fullName() + " · " : "") + loan.getStatus().value());
        dialog.getDialogPane().getButtonTypes().add(ButtonType.CLOSE);
        dialog.setResizable(true);

        long paid = installments.stream().mapToLong(LoanInstallment::amountPaid).sum();
        long overdue = installments.stream()
                .filter(installment -> installment.getStatus() != enums.InstallmentStatus.PAID && installment.getDueDate().isBefore(LocalDate.now()))
                .mapToLong(installment -> installment.totalDue() - installment.amountPaid())
                .sum();

        GridPane overview = grid();
        int row = 0;
        addRow(overview, row++, "Loan balance:", new Label(Money.format(loan.getOutstandingBalance())));
        addRow(overview, row++, "Amount paid:", new Label(Money.format(paid)));
        addRow(overview, row++, "Amount overdue:", new Label(Money.format(overdue)));
        addRow(overview, row++, "Loan amount:", new Label(Money.format(loan.getPrincipalAmount())));
        addRow(overview, row++, "Interest:", new Label(percent(loan.getInterestRateBps()) + "% per period, "
                + loan.getInterestMethod().value() + " (" + Money.format(loan.getTotalInterest()) + ")"));
        addRow(overview, row++, "Repayments:", new Label(loan.getTermPeriodCount() + " × " + loan.getRepaymentFrequency().value()));
        addRow(overview, row++, "Charges deducted:", new Label(Money.format(loan.getOriginationFeeAmount())));
        addRow(overview, row++, "Purpose:", new Label(nullToEmpty(loan.getPurpose())));
        addRow(overview, row++, "First repayment date:", new Label(loan.getFirstRepaymentDate() == null ? "One period after disbursement" : loan.getFirstRepaymentDate().toString()));
        addRow(overview, row, "Disbursed:", new Label(loan.getDisbursedAt() == null ? "—" : loan.getDisbursedAt().toString().substring(0, 10)));

        TableView<LoanInstallment> schedule = table(installments, List.of(
                column("#", installment -> String.valueOf(installment.getSequence())),
                column("Due date", installment -> installment.getDueDate().toString()),
                column("Principal", installment -> Money.format(installment.getPrincipalDue())),
                column("Interest", installment -> Money.format(installment.getInterestDue())),
                column("Penalty", installment -> Money.format(installment.getPenaltyDue())),
                column("Paid", installment -> Money.format(installment.amountPaid())),
                column("Status", installment -> installment.getStatus().value())));

        TabPane tabs = new TabPane(
                tab("Overview", overview),
                tab("Repayment Schedule", schedule),
                tab("Collateral", table(collaterals, List.of(
                        column("Type", LoanApplicationDetails.Collateral::type),
                        column("Description", LoanApplicationDetails.Collateral::description),
                        column("Serial / ref.", collateral -> nullToEmpty(collateral.serialNumber())),
                        column("Value", collateral -> Money.format(collateral.estimatedValue()))))),
                tab("Guarantors", table(guarantors, List.of(
                        column("Name", LoanApplicationDetails.Guarantor::name),
                        column("Phone", guarantor -> nullToEmpty(guarantor.phone())),
                        column("Relationship", guarantor -> nullToEmpty(guarantor.relationship())),
                        column("Guaranteed", guarantor -> guarantor.guaranteedAmount() == null ? "—" : Money.format(guarantor.guaranteedAmount()))))),
                tab("Charges", table(charges, List.of(
                        column("Charge", LoanApplicationDetails.Charge::name),
                        column("Amount", charge -> Money.format(charge.amount()))))));
        tabs.setPrefSize(760, 440);
        dialog.getDialogPane().setContent(tabs);
        dialog.showAndWait();
    }

    // ------------------------------------------------------------ recalculate

    static Optional<RecalculateInput> recalculate(String loanNumber, LocalDate nextUnpaidDue) {
        Dialog<RecalculateInput> dialog = new Dialog<>();
        dialog.setTitle("Recalculate Schedule");
        dialog.setHeaderText(loanNumber + " — re-dates every unpaid installment, one period apart."
                + " Amounts and payments are not changed.");
        ButtonType recalcType = new ButtonType("Recalculate", ButtonBar.ButtonData.OK_DONE);
        dialog.getDialogPane().getButtonTypes().addAll(recalcType, ButtonType.CANCEL);

        DatePicker datePicker = new DatePicker();
        datePicker.setPromptText("Leave empty to rebuild from the loan's own rule");
        TextArea reasonArea = new TextArea();
        reasonArea.setPrefRowCount(3);
        reasonArea.setPromptText("Reason (optional)");

        GridPane grid = grid();
        addRow(grid, 0, "Next unpaid installment is due:", new Label(nextUnpaidDue == null ? "—" : nextUnpaidDue.toString()));
        addRow(grid, 1, "New due date:", datePicker);
        addRow(grid, 2, "Reason:", reasonArea);
        dialog.getDialogPane().setContent(grid);

        dialog.setResultConverter(buttonType -> buttonType == recalcType
                ? new RecalculateInput(datePicker.getValue(), blankToNull(reasonArea.getText()))
                : null);

        return dialog.showAndWait();
    }

    // ------------------------------------------------------------- calculator

    /** "Loan terms for repayment calculation" — nothing is saved. */
    static void calculator(List<LoanProduct> products) {
        Dialog<Void> dialog = new Dialog<>();
        dialog.setTitle("Loan Calculator");
        dialog.setHeaderText("Loan terms for repayment calculation");
        dialog.getDialogPane().getButtonTypes().add(ButtonType.CLOSE);
        dialog.setResizable(true);

        ComboBox<LoanProduct> productCombo = new ComboBox<>(FXCollections.observableArrayList(products));
        productCombo.setPromptText("Fill terms from a product (optional)");
        productCombo.setConverter(new javafx.util.StringConverter<>() {
            @Override public String toString(LoanProduct product) { return product == null ? "" : product.getName(); }
            @Override public LoanProduct fromString(String string) { return null; }
        });
        TextField principalField = new TextField();
        TextField termField = new TextField("6");
        ComboBox<LoanFrequency> frequencyCombo = new ComboBox<>(FXCollections.observableArrayList(LoanFrequency.values()));
        frequencyCombo.setValue(LoanFrequency.WEEKLY);
        TextField rateField = new TextField("0");
        ComboBox<InterestMethod> methodCombo = new ComboBox<>(FXCollections.observableArrayList(InterestMethod.values()));
        methodCombo.setValue(InterestMethod.FLAT);
        TextField chargesField = new TextField("0");
        DatePicker disbursementPicker = new DatePicker(LocalDate.now());
        DatePicker firstDatePicker = new DatePicker();

        productCombo.setOnAction(event -> {
            LoanProduct product = productCombo.getValue();
            if (product != null) {
                termField.setText(String.valueOf(product.getTermPeriodCount()));
                frequencyCombo.setValue(product.getRepaymentFrequency());
                rateField.setText(percent(product.getInterestRateBps()));
                methodCombo.setValue(product.getInterestMethod());
                chargesField.setText(cedis(product.getOriginationFeeAmount()));
            }
        });

        GridPane form = grid();
        addRow(form, 0, "Product:", productCombo);
        addRow(form, 1, "Loan principal (GHS):", principalField);
        addRow(form, 2, "Number of repayments:", termField);
        addRow(form, 3, "Repayment every:", frequencyCombo);
        addRow(form, 4, "Interest rate (% per period):", rateField);
        addRow(form, 5, "Interest method:", methodCombo);
        addRow(form, 6, "Charges (GHS):", chargesField);
        addRow(form, 7, "Disbursement date:", disbursementPicker);
        addRow(form, 8, "First repayment date:", firstDatePicker);

        VBox result = new VBox();
        Button calculate = new Button("Calculate");
        calculate.getStyleClass().add("button-primary");
        calculate.setOnAction(event -> {
            try {
                result.getChildren().setAll(scheduleTable(money(principalField.getText()),
                        (int) Math.round(Double.parseDouble(rateField.getText().trim()) * 100),
                        Integer.parseInt(termField.getText().trim()), methodCombo.getValue(), frequencyCombo.getValue(),
                        disbursementPicker.getValue() != null ? disbursementPicker.getValue() : LocalDate.now(),
                        firstDatePicker.getValue(), money(chargesField.getText())));
            } catch (Exception e) {
                result.getChildren().setAll(new Label("Check the terms: " + e.getMessage()));
            }
        });

        VBox content = new VBox(10, form, calculate, result);
        content.setPadding(new Insets(10));
        ScrollPane scroll = new ScrollPane(content);
        scroll.setFitToWidth(true);
        scroll.setPrefSize(760, 560);
        dialog.getDialogPane().setContent(scroll);
        dialog.showAndWait();
    }

    // ---------------------------------------------------------------- helpers

    /** Schedule table plus totals, built by the same ScheduleGenerator disbursement uses. */
    private static Node scheduleTable(long principal, int rateBps, int terms, InterestMethod method, LoanFrequency frequency,
                                      LocalDate disbursementDate, LocalDate firstDueDate, long charges) {
        if (principal <= 0 || terms <= 0 || method == null || frequency == null) {
            return new Label("Enter a principal and the terms first.");
        }
        List<ScheduledInstallment> schedule = new ScheduleGenerator()
                .generate(principal, rateBps, terms, method, frequency, disbursementDate, firstDueDate);
        long totalInterest = schedule.stream().mapToLong(ScheduledInstallment::interestDue).sum();

        List<String[]> rows = new ArrayList<>();
        long balance = principal;
        for (ScheduledInstallment installment : schedule) {
            balance -= installment.principalDue();
            rows.add(new String[] {String.valueOf(installment.sequence()), installment.dueDate().toString(),
                    Money.format(installment.principalDue()), Money.format(installment.interestDue()),
                    Money.format(installment.principalDue() + installment.interestDue()), Money.format(balance)});
        }

        TableView<String[]> table = table(rows, List.of(
                column("#", row -> row[0]), column("Due date", row -> row[1]), column("Principal", row -> row[2]),
                column("Interest", row -> row[3]), column("Total", row -> row[4]), column("Balance", row -> row[5])));
        table.setPrefHeight(240);

        Label totals = new Label("Total interest " + Money.format(totalInterest)
                + " · Total repayable " + Money.format(principal + totalInterest)
                + " · Charges " + Money.format(charges)
                + " · Cash to client " + Money.format(principal - charges)
                + " · Matures " + schedule.get(schedule.size() - 1).dueDate());
        totals.setWrapText(true);
        return new VBox(8, totals, table);
    }

    private static <T> TableView<T> table(List<T> items, List<TableColumn<T, String>> columns) {
        TableView<T> table = new TableView<>(FXCollections.observableArrayList(items));
        table.getColumns().addAll(columns);
        table.setColumnResizePolicy(TableView.CONSTRAINED_RESIZE_POLICY_FLEX_LAST_COLUMN);
        table.setPlaceholder(new Label("Nothing recorded."));
        return table;
    }

    private static <T> TableColumn<T, String> column(String title, Function<T, String> value) {
        TableColumn<T, String> column = new TableColumn<>(title);
        column.setCellValueFactory(data -> new SimpleStringProperty(value.apply(data.getValue())));
        return column;
    }

    private static Tab tab(String title, Node content) {
        Tab tab = new Tab(title, content);
        tab.setClosable(false);
        return tab;
    }

    private static GridPane grid() {
        GridPane grid = new GridPane();
        grid.setHgap(8);
        grid.setVgap(8);
        grid.setPadding(new Insets(10));
        return grid;
    }

    private static void addRow(GridPane grid, int row, String label, Node field) {
        grid.add(new Label(label), 0, row);
        grid.add(field, 1, row);
    }

    private static long money(String text) {
        return text == null || text.isBlank() ? 0 : Money.toMinorUnits(text.trim());
    }

    private static String cedis(long minor) {
        return String.format("%.2f", minor / 100.0);
    }

    private static String percent(int bps) {
        return bps % 100 == 0 ? String.valueOf(bps / 100) : String.valueOf(bps / 100.0);
    }

    private static <T> T or(T value, T fallback) {
        return value != null ? value : fallback;
    }

    private static void require(String value, String message) {
        if (value == null || value.isBlank()) {
            throw new IllegalArgumentException(message);
        }
    }

    private static String blankToNull(String value) {
        return value == null || value.isBlank() ? null : value.trim();
    }

    private static String nullToEmpty(String value) {
        return value == null ? "" : value;
    }

    /** A growable list of rows of text fields with per-row remove buttons — the modal-style repeater. */
    private static final class RowEditor {
        private final List<String> headers;
        private final VBox rowsBox = new VBox(6);
        private final List<List<TextField>> fields = new ArrayList<>();
        private final VBox root;

        RowEditor(List<String> headers, String addLabel) {
            this.headers = headers;
            Button add = new Button(addLabel);
            add.getStyleClass().add("button-secondary");
            add.setOnAction(event -> addRow(new String[headers.size()]));
            ScrollPane scroll = new ScrollPane(rowsBox);
            scroll.setFitToWidth(true);
            scroll.setPrefHeight(300);
            root = new VBox(8, scroll, add);
            root.setPadding(new Insets(10));
        }

        void addRow(String... values) {
            List<TextField> rowFields = new ArrayList<>();
            HBox row = new HBox(6);
            for (int i = 0; i < headers.size(); i++) {
                TextField field = new TextField(i < values.length && values[i] != null ? values[i] : "");
                field.setPromptText(headers.get(i));
                HBox.setHgrow(field, javafx.scene.layout.Priority.ALWAYS);
                rowFields.add(field);
                row.getChildren().add(field);
            }
            Button remove = new Button("Remove");
            remove.getStyleClass().add("button-secondary");
            remove.setOnAction(event -> {
                rowsBox.getChildren().remove(row);
                fields.remove(rowFields);
            });
            row.getChildren().add(remove);
            fields.add(rowFields);
            rowsBox.getChildren().add(row);
        }

        /** Non-empty rows as trimmed strings. */
        List<List<String>> rows() {
            List<List<String>> rows = new ArrayList<>();
            for (List<TextField> rowFields : fields) {
                List<String> values = rowFields.stream().map(field -> field.getText().trim()).toList();
                if (values.stream().anyMatch(value -> !value.isEmpty())) {
                    rows.add(values);
                }
            }
            return rows;
        }

        Node node() {
            return root;
        }
    }
}
