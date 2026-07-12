package com.ogua.susudesktop;

import db.AppConfig;
import db.DatabaseConnection;
import db.SessionManager;
import javafx.fxml.FXML;
import javafx.scene.control.Label;
import javafx.stage.Stage;
import models.LocalUser;

public class MainController {

    @FXML private Label userLabel;
    @FXML private Label modeLabel;
    @FXML private Label storageLabel;

    @FXML
    private void initialize() {
        LocalUser user = SessionManager.getCurrentUser();
        if (user != null) {
            userLabel.setText(user.getName() + " (" + user.getRole().replace('_', ' ') + ")");
        }

        modeLabel.setText(AppConfig.isSyncEnabled() ? "Hybrid — syncs with server" : "Standalone — offline");
        storageLabel.setText("Storage: " + DatabaseConnection.getActiveProvider().getType().toUpperCase());
    }

    @FXML
    private void onLogout() {
        SessionManager.clearSession();
        Navigator.showLogin((Stage) userLabel.getScene().getWindow());
    }
}
