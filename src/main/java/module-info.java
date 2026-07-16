module com.ogua.susudesktop {
    requires javafx.controls;
    requires javafx.fxml;
    requires javafx.web;

    requires com.dlsc.formsfx;
    requires net.synedra.validatorfx;
    requires org.kordamp.ikonli.javafx;
    requires org.kordamp.bootstrapfx.core;

    requires java.sql;
    requires java.net.http;
    requires java.desktop;
    requires com.zaxxer.hikari;
    requires org.xerial.sqlitejdbc;
    requires jbcrypt;
    requires org.json;

    opens com.ogua.susudesktop to javafx.fxml;
    opens db to javafx.fxml;
    opens models to javafx.base;
    opens service to javafx.fxml;
    exports com.ogua.susudesktop;
}
