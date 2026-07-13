package com.ogua.susudesktop;

import java.util.List;
import javafx.beans.property.SimpleStringProperty;
import javafx.collections.FXCollections;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.control.Label;
import javafx.scene.control.TableColumn;
import javafx.scene.control.TableView;
import models.PaymentIntent;
import service.PaymentIntentService;
import support.Money;

/** Read-only hybrid-mode view — the desktop never initiates payments itself (Phase 4 scope: mobile/web only). */
public class PaymentIntentsController {

    @FXML private TableView<PaymentIntent> table;
    @FXML private TableColumn<PaymentIntent, String> channelColumn;
    @FXML private TableColumn<PaymentIntent, String> phoneColumn;
    @FXML private TableColumn<PaymentIntent, String> amountColumn;
    @FXML private TableColumn<PaymentIntent, String> statusColumn;
    @FXML private TableColumn<PaymentIntent, String> createdAtColumn;
    @FXML private Label statusLabel;

    private final PaymentIntentService paymentIntentService = new PaymentIntentService();

    @FXML
    private void initialize() {
        channelColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getChannel() != null ? data.getValue().getChannel().toUpperCase() : ""));
        phoneColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getPhone() != null ? data.getValue().getPhone() : ""));
        amountColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getAmountFormatted() != null
                        ? data.getValue().getAmountFormatted()
                        : Money.format(data.getValue().getAmount())));
        statusColumn.setCellValueFactory(data -> new SimpleStringProperty(data.getValue().getStatus()));
        createdAtColumn.setCellValueFactory(data -> new SimpleStringProperty(
                data.getValue().getCreatedAt() != null ? data.getValue().getCreatedAt() : ""));

        refresh();
    }

    @FXML
    private void refresh() {
        statusLabel.setText("Loading…");

        Task<List<PaymentIntent>> task = new Task<>() {
            @Override
            protected List<PaymentIntent> call() throws Exception {
                return paymentIntentService.list();
            }
        };

        task.setOnSucceeded(event -> {
            table.setItems(FXCollections.observableArrayList(task.getValue()));
            statusLabel.setText("");
        });
        task.setOnFailed(event -> statusLabel.setText(
                "Could not load payments: " + task.getException().getMessage()));

        new Thread(task, "payment-intents-load").start();
    }
}
