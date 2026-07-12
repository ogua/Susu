package com.ogua.susudesktop;

import java.io.IOException;
import javafx.fxml.FXMLLoader;
import javafx.geometry.Rectangle2D;
import javafx.scene.Node;
import javafx.scene.Parent;
import javafx.scene.Scene;
import javafx.scene.image.Image;
import javafx.stage.Screen;
import javafx.stage.Stage;
import org.kordamp.bootstrapfx.BootstrapFX;

/** Central scene switching so controllers never build scenes themselves. */
public final class Navigator {

    private static final Image APP_ICON = new Image(
            Navigator.class.getResourceAsStream("images/susu-logo.png"));

    private Navigator() {}

    public static void showSetup(Stage stage) {
        show(stage, "setup-view.fxml", 640, 680, 540, 520);
    }

    public static void showLogin(Stage stage) {
        show(stage, "login-view.fxml", 440, 420, 400, 400);
    }

    public static void showMain(Stage stage) {
        show(stage, "main-view.fxml", 1024, 680, 760, 480);
    }

    private static void show(Stage stage, String fxml, double preferredWidth, double preferredHeight,
                              double minWidth, double minHeight) {
        try {
            FXMLLoader loader = new FXMLLoader(Navigator.class.getResource(fxml));
            Parent root = loader.load();

            // Cap the requested size to the visible work area (screen minus
            // taskbar) so the window — and its title bar with the
            // minimize/maximize/close controls — never exceeds what a smaller
            // or heavily-DPI-scaled display can actually show.
            Rectangle2D visualBounds = Screen.getPrimary().getVisualBounds();
            double width = Math.min(preferredWidth, visualBounds.getWidth() * 0.9);
            double height = Math.min(preferredHeight, visualBounds.getHeight() * 0.9);

            Scene scene = new Scene(root, width, height);
            scene.getStylesheets().addAll(
                    BootstrapFX.bootstrapFXStylesheet(),
                    Navigator.class.getResource("styles/design-system.css").toExternalForm(),
                    Navigator.class.getResource("styles/components.css").toExternalForm()
            );
            stage.setScene(scene);
            stage.getIcons().setAll(APP_ICON);
            stage.setMinWidth(Math.min(minWidth, visualBounds.getWidth() * 0.9));
            stage.setMinHeight(Math.min(minHeight, visualBounds.getHeight() * 0.9));
            stage.centerOnScreen();

            // centerOnScreen() can still place the title bar above the visible
            // work area on short screens (seen at 150% Windows scaling); pin
            // the window fully on-screen so it's always reachable.
            if (stage.getY() < visualBounds.getMinY()) {
                stage.setY(visualBounds.getMinY());
            }
            if (stage.getX() < visualBounds.getMinX()) {
                stage.setX(visualBounds.getMinX());
            }

            Animations.fadeInScreen(root);
            for (Node button : root.lookupAll(".button")) {
                Animations.attachPressFeedback(button);
            }
        } catch (IOException e) {
            throw new IllegalStateException("Could not load view " + fxml + ": " + e.getMessage(), e);
        }
    }
}
