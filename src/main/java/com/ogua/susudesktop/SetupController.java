package com.ogua.susudesktop;

import db.AppConfig;
import db.DatabaseConnection;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.control.Button;
import javafx.scene.control.Label;
import javafx.scene.control.PasswordField;
import javafx.scene.control.RadioButton;
import javafx.scene.control.TextField;
import javafx.scene.layout.GridPane;
import javafx.stage.Stage;
import service.AuthService;

/**
 * First-run wizard: storage profile (SQLite / MySQL), mode (standalone /
 * hybrid), and the initial admin account. Everything is written to
 * ~/.susudesktop/config.properties and the local database.
 */
public class SetupController {

    @FXML private RadioButton sqliteRadio;
    @FXML private RadioButton mysqlRadio;
    @FXML private GridPane mysqlPane;
    @FXML private TextField mysqlHost;
    @FXML private TextField mysqlPort;
    @FXML private TextField mysqlDatabase;
    @FXML private TextField mysqlUsername;
    @FXML private PasswordField mysqlPassword;

    @FXML private RadioButton standaloneRadio;
    @FXML private RadioButton hybridRadio;
    @FXML private GridPane hybridPane;
    @FXML private TextField apiBaseUrl;

    @FXML private TextField adminName;
    @FXML private TextField adminEmail;
    @FXML private PasswordField adminPassword;

    @FXML private Label statusLabel;
    @FXML private Button finishButton;

    private final AuthService authService = new AuthService();

    @FXML
    private void initialize() {
        mysqlPane.disableProperty().bind(mysqlRadio.selectedProperty().not());
        hybridPane.disableProperty().bind(hybridRadio.selectedProperty().not());
    }

    @FXML
    private void onFinish() {
        statusLabel.setText("");

        if (adminName.getText().isBlank() || adminEmail.getText().isBlank()
                || adminPassword.getText().length() < 8) {
            statusLabel.setText("Provide the admin's name, email, and a password of at least 8 characters.");
            return;
        }

        finishButton.setDisable(true);
        statusLabel.setText("Setting up the database…");

        Task<Void> task = new Task<>() {
            @Override
            protected Void call() throws Exception {
                DatabaseConnection.resetProvider();

                if (mysqlRadio.isSelected()) {
                    AppConfig.applyMySQLDefaults(
                            mysqlHost.getText().trim(),
                            mysqlPort.getText().trim(),
                            mysqlDatabase.getText().trim(),
                            mysqlUsername.getText().trim(),
                            mysqlPassword.getText());
                } else {
                    AppConfig.applySQLiteDefaults();
                }

                AppConfig.set("sync.enabled", String.valueOf(hybridRadio.isSelected()));
                if (hybridRadio.isSelected()) {
                    AppConfig.set("api.base_url", apiBaseUrl.getText().trim().replaceAll("/+$", ""));
                }

                // Connects + runs migrations; throws if the profile is unusable.
                DatabaseConnection.getActiveProvider();

                if (!new AuthService().hasAnyUser()) {
                    authService.createUser(
                            adminName.getText().trim(),
                            adminEmail.getText().trim(),
                            null,
                            "company_admin",
                            adminPassword.getText());
                }

                AppConfig.markSetupComplete();
                return null;
            }
        };

        task.setOnSucceeded(event ->
                Navigator.showLogin((Stage) finishButton.getScene().getWindow()));
        task.setOnFailed(event -> {
            finishButton.setDisable(false);
            Throwable ex = task.getException();
            statusLabel.setText("Setup failed: " + (ex != null ? ex.getMessage() : "unknown error"));
        });

        new Thread(task, "setup-task").start();
    }
}
