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

public class LoginController {

    @FXML private TextField emailField;
    @FXML private PasswordField passwordField;
    @FXML private Label statusLabel;
    @FXML private Button loginButton;

    private final AuthService authService = new AuthService();

    @FXML
    private void onLogin() {
        statusLabel.setText("");
        loginButton.setDisable(true);

        String email = emailField.getText();
        String password = passwordField.getText();

        Task<AuthService.LoginResult> task = new Task<>() {
            @Override
            protected AuthService.LoginResult call() {
                return authService.login(email, password);
            }
        };

        task.setOnSucceeded(event -> {
            AuthService.LoginResult result = task.getValue();
            if (result.status() == AuthService.LoginResult.Status.SUCCESS) {
                SessionManager.setCurrentUser(result.user());
                Navigator.showMain((Stage) loginButton.getScene().getWindow());
            } else {
                loginButton.setDisable(false);
                statusLabel.setText(result.message());
            }
        });
        task.setOnFailed(event -> {
            loginButton.setDisable(false);
            statusLabel.setText("Login failed unexpectedly.");
        });

        new Thread(task, "login-task").start();
    }
}
