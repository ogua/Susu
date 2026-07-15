package com.ogua.susudesktop;

import db.AppConfig;
import db.DatabaseConnection;
import db.provider.DatabaseProvider;
import db.provider.MySQLProvider;
import db.provider.SQLiteProvider;
import java.io.File;
import java.util.List;
import javafx.concurrent.Task;
import javafx.fxml.FXML;
import javafx.scene.control.Button;
import javafx.scene.control.Label;
import javafx.scene.control.PasswordField;
import javafx.scene.control.RadioButton;
import javafx.scene.control.TextField;
import javafx.scene.layout.GridPane;
import javafx.stage.Stage;
import service.EngineSwitchService;

public class EngineSwitchController {

    @FXML private Label currentEngineLabel;
    @FXML private RadioButton sqliteRadio;
    @FXML private RadioButton mysqlRadio;
    @FXML private GridPane mysqlPane;
    @FXML private TextField mysqlHost;
    @FXML private TextField mysqlPort;
    @FXML private TextField mysqlDatabase;
    @FXML private TextField mysqlUsername;
    @FXML private PasswordField mysqlPassword;
    @FXML private Label progressLabel;
    @FXML private Label statusLabel;
    @FXML private Button migrateButton;

    private final EngineSwitchService engineSwitchService = new EngineSwitchService();

    @FXML
    private void initialize() {
        mysqlPane.disableProperty().bind(mysqlRadio.selectedProperty().not());

        String currentType = DatabaseConnection.getActiveProvider().getType();
        currentEngineLabel.setText("Currently using: " + currentType.toUpperCase());

        // A same-type target would either self-copy (SQLite's path is always
        // the one deterministic default file) or need credentials for a
        // brand-new MySQL database, which is what the setup wizard is for —
        // this screen only offers the other engine.
        if ("sqlite".equalsIgnoreCase(currentType)) {
            sqliteRadio.setDisable(true);
            mysqlRadio.setSelected(true);
        } else {
            mysqlRadio.setDisable(true);
            sqliteRadio.setSelected(true);
        }
    }

    @FXML
    private void onMigrate() {
        statusLabel.setText("");
        progressLabel.setText("");
        migrateButton.setDisable(true);

        Task<List<EngineSwitchService.TableResult>> task = new Task<>() {
            @Override
            protected List<EngineSwitchService.TableResult> call() throws Exception {
                updateMessage("Connecting to target database…");
                DatabaseProvider target = buildTargetProvider();

                if (target.hasExistingData()) {
                    target.close();
                    throw new IllegalStateException(
                            "The target database already has data — choose an empty database.");
                }
                target.initialize();

                updateMessage("Copying data…");
                List<EngineSwitchService.TableResult> results = engineSwitchService.migrate(target);
                target.close();
                return results;
            }
        };

        task.messageProperty().addListener((obs, old, msg) -> progressLabel.setText(msg));
        task.setOnSucceeded(event -> {
            List<EngineSwitchService.TableResult> results = task.getValue();
            boolean allMatch = results.stream().allMatch(EngineSwitchService.TableResult::matches);

            if (!allMatch) {
                migrateButton.setDisable(false);
                statusLabel.setText(
                        "Row counts did not match after copying — the old database was left untouched. Try again.");
                return;
            }

            commitSwitch();
        });
        task.setOnFailed(event -> {
            migrateButton.setDisable(false);
            Throwable ex = task.getException();
            statusLabel.setText("Could not switch engines: " + (ex != null ? ex.getMessage() : "unknown error"));
        });

        new Thread(task, "engine-switch-task").start();
    }

    private DatabaseProvider buildTargetProvider() {
        if (mysqlRadio.isSelected()) {
            String url = "jdbc:mysql://" + mysqlHost.getText().trim() + ":" + mysqlPort.getText().trim()
                    + "/" + mysqlDatabase.getText().trim()
                    + "?useSSL=false&allowPublicKeyRetrieval=true&serverTimezone=UTC";
            return new MySQLProvider(url, mysqlUsername.getText().trim(), mysqlPassword.getText());
        }

        File dbFile = new File(AppConfig.getAppDir(), AppConfig.SQLITE_DB_FILE_NAME);
        return new SQLiteProvider("jdbc:sqlite:" + dbFile.getAbsolutePath());
    }

    private void commitSwitch() {
        progressLabel.setText("Switching over…");
        DatabaseConnection.resetProvider();

        if (mysqlRadio.isSelected()) {
            AppConfig.applyMySQLDefaults(
                    mysqlHost.getText().trim(), mysqlPort.getText().trim(),
                    mysqlDatabase.getText().trim(), mysqlUsername.getText().trim(), mysqlPassword.getText());
        } else {
            AppConfig.applySQLiteDefaults();
        }

        Navigator.showLogin((Stage) migrateButton.getScene().getWindow());
    }
}
