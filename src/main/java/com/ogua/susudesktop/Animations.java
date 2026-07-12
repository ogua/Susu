package com.ogua.susudesktop;

import javafx.animation.FadeTransition;
import javafx.animation.Interpolator;
import javafx.animation.ScaleTransition;
import javafx.animation.TranslateTransition;
import javafx.scene.Node;
import javafx.scene.Parent;
import javafx.util.Duration;

/** Shared micro-interactions applied by {@link Navigator} so no controller has to wire animation code itself. */
final class Animations {

    private Animations() {}

    static void fadeInScreen(Parent root) {
        root.setOpacity(0);
        root.setTranslateY(8);

        FadeTransition fade = new FadeTransition(Duration.millis(240), root);
        fade.setFromValue(0);
        fade.setToValue(1);

        TranslateTransition slide = new TranslateTransition(Duration.millis(240), root);
        slide.setFromY(8);
        slide.setToY(0);
        slide.setInterpolator(Interpolator.EASE_OUT);

        fade.play();
        slide.play();
    }

    static void attachPressFeedback(Node node) {
        ScaleTransition press = new ScaleTransition(Duration.millis(90), node);
        press.setInterpolator(Interpolator.EASE_OUT);

        node.setOnMousePressed(e -> {
            press.stop();
            press.setToX(0.97);
            press.setToY(0.97);
            press.playFromStart();
        });
        node.setOnMouseReleased(e -> {
            press.stop();
            press.setToX(1);
            press.setToY(1);
            press.playFromStart();
        });
    }
}
