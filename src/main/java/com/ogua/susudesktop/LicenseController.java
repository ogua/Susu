package com.ogua.susudesktop;

import db.AppConfig;
import javafx.fxml.FXML;
import javafx.scene.control.Button;
import javafx.scene.control.Label;
import javafx.scene.control.TextArea;
import javafx.scene.input.Clipboard;
import javafx.scene.input.ClipboardContent;
import javafx.stage.Stage;
import service.LicenseManager;

public class LicenseController {

    @FXML private Label installIdLabel;
    @FXML private Label contextLabel;
    @FXML private TextArea keyField;
    @FXML private Label statusLabel;
    @FXML private Button activateButton;

    @FXML
    private void initialize() {
        installIdLabel.setText(AppConfig.getInstallId());

        LicenseManager.LicenseStatus pending = LicenseManager.pendingStatus;
        LicenseManager.pendingStatus = null;
        contextLabel.setText(contextMessage(pending));
    }

    private String contextMessage(LicenseManager.LicenseStatus status) {
        if (status == null) {
            return "Enter an activation key to continue.";
        }
        return switch (status) {
            case EXPIRED -> "Your license has expired. Enter a new activation key to continue.";
            case TAMPERED -> "This install's license could not be verified — it may have been edited or the system"
                    + " clock changed. Enter a valid activation key.";
            case MISSING -> "This install has not been activated yet. Enter an activation key to continue.";
            case GRACE_PERIOD -> "Your license has expired and is in its grace period. Renew now to avoid a lockout.";
            case VALID -> "Enter an activation key to continue.";
        };
    }

    @FXML
    private void onCopyInstallId() {
        ClipboardContent content = new ClipboardContent();
        content.putString(AppConfig.getInstallId());
        Clipboard.getSystemClipboard().setContent(content);
        statusLabel.getStyleClass().setAll("text-success");
        statusLabel.setText("Install ID copied to clipboard.");
    }

    @FXML
    private void onActivate() {
        statusLabel.getStyleClass().setAll("text-danger");
        statusLabel.setText("");

        try {
            LicenseManager.validateAndStoreKey(keyField.getText());
        } catch (LicenseManager.LicenseException e) {
            statusLabel.setText(e.getMessage());
            return;
        }

        Navigator.showLogin((Stage) activateButton.getScene().getWindow());
    }
}
