package com.ogua.susudesktop;

import db.AppConfig;
import db.DatabaseConnection;
import javafx.application.Application;
import javafx.stage.Stage;
import service.LicenseManager;

/**
 * SusuApp Desktop — offline back-office client.
 *
 * Startup flow: first run (no completed setup) opens the setup wizard to pick
 * a storage profile (SQLite / MySQL) and mode (standalone / hybrid) and create
 * the first admin. Every later run checks the license first — VALID or
 * GRACE_PERIOD go straight to login; anything else (MISSING, EXPIRED beyond
 * grace, TAMPERED) routes to the activation screen instead.
 */
public class SusuDesktopApplication extends Application {

    @Override
    public void start(Stage stage) {
        stage.setTitle("SusuApp Desktop");

        if (!AppConfig.isSetupComplete()) {
            Navigator.showSetup(stage);
        } else {
            LicenseManager.LicenseStatus status = LicenseManager.getLicenseStatus();
            if (status == LicenseManager.LicenseStatus.VALID || status == LicenseManager.LicenseStatus.GRACE_PERIOD) {
                Navigator.showLogin(stage);
            } else {
                LicenseManager.pendingStatus = status;
                Navigator.showLicense(stage);
            }
        }

        stage.show();
    }

    @Override
    public void stop() {
        DatabaseConnection.shutdown();
    }
}
