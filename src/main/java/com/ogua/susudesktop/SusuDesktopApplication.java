package com.ogua.susudesktop;

import db.AppConfig;
import db.DatabaseConnection;
import javafx.application.Application;
import javafx.stage.Stage;

/**
 * SusuApp Desktop — offline back-office client.
 *
 * Startup flow: first run (no completed setup) opens the setup wizard to pick
 * a storage profile (SQLite / MySQL) and mode (standalone / hybrid) and create
 * the first admin; every later run goes straight to login.
 */
public class SusuDesktopApplication extends Application {

    @Override
    public void start(Stage stage) {
        stage.setTitle("SusuApp Desktop");

        if (AppConfig.isSetupComplete()) {
            Navigator.showLogin(stage);
        } else {
            Navigator.showSetup(stage);
        }

        stage.show();
    }

    @Override
    public void stop() {
        DatabaseConnection.shutdown();
    }
}
