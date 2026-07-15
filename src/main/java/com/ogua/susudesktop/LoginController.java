package com.ogua.susudesktop;

import db.SessionManager;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.control.Button;
import javafx.scene.control.Label;
import javafx.scene.control.PasswordField;
import javafx.scene.control.TextField;
import javafx.stage.Stage;
import service.AuthService;
import service.LicenseManager;

public class LoginController {

    @FXML private TextField emailField;
    @FXML private PasswordField passwordField;
    @FXML private Label statusLabel;
    @FXML private Button loginButton;

    private final AuthService authService = new AuthService();

    private record LoginOutcome(AuthService.LoginResult result, LicenseManager.LicenseStatus licenseStatus) {}

    @FXML
    private void onLogin() {
        statusLabel.setText("");
        loginButton.setDisable(true);

        String email = emailField.getText();
        String password = passwordField.getText();

        Task<LoginOutcome> task = new Task<>() {
            @Override
            protected LoginOutcome call() {
                AuthService.LoginResult result = authService.login(email, password);
                // Re-checked here (not just at app startup) so a session that
                // outlives its license — or is opened while a background
                // renewal never landed — still gets caught before the main
                // shell loads.
                LicenseManager.LicenseStatus licenseStatus = result.status() == AuthService.LoginResult.Status.SUCCESS
                        ? LicenseManager.getLicenseStatus() : null;
                return new LoginOutcome(result, licenseStatus);
            }
        };

        task.setOnSucceeded(event -> {
            LoginOutcome outcome = task.getValue();
            AuthService.LoginResult result = outcome.result();

            if (result.status() != AuthService.LoginResult.Status.SUCCESS) {
                loginButton.setDisable(false);
                statusLabel.setText(result.message());
                return;
            }

            Stage stage = (Stage) loginButton.getScene().getWindow();
            if (outcome.licenseStatus() == LicenseManager.LicenseStatus.VALID
                    || outcome.licenseStatus() == LicenseManager.LicenseStatus.GRACE_PERIOD) {
                SessionManager.setCurrentUser(result.user());
                Navigator.showMain(stage);
            } else {
                LicenseManager.pendingStatus = outcome.licenseStatus();
                Navigator.showLicense(stage);
            }
        });
        task.setOnFailed(event -> {
            loginButton.setDisable(false);
            statusLabel.setText("Login failed unexpectedly.");
        });

        new Thread(task, "login-task").start();
    }
}
