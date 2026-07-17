package com.ogua.susudesktop;

import java.io.File;
import java.util.List;
import javafx.concurrent.Task;
import javafx.scene.control.Label;
import javafx.stage.FileChooser;
import javafx.stage.Window;
import service.export.CsvExporter;
import service.export.PdfExporter;

/**
 * Shared save-dialog + background-write flow for every report screen's
 * Export PDF / Export CSV buttons. Exports operate on the rows already
 * loaded into the table (never the database — see the exporters' contract).
 */
public final class ReportExporter {

    private ReportExporter() {
    }

    public static void exportCsv(Window owner, String baseName, List<String> headers,
                                 List<List<String>> rows, Label statusLabel) {
        File target = choose(owner, baseName + ".csv", new FileChooser.ExtensionFilter("CSV files", "*.csv"));
        if (target == null) {
            return;
        }
        run(statusLabel, target, () -> CsvExporter.write(target, headers, rows));
    }

    public static void exportPdf(Window owner, String baseName, String title, List<String> metaLines,
                                 List<String> headers, List<List<String>> rows, Label statusLabel) {
        File target = choose(owner, baseName + ".pdf", new FileChooser.ExtensionFilter("PDF files", "*.pdf"));
        if (target == null) {
            return;
        }
        run(statusLabel, target, () -> PdfExporter.write(target, title, metaLines, headers, rows));
    }

    private static File choose(Window owner, String initialName, FileChooser.ExtensionFilter filter) {
        FileChooser chooser = new FileChooser();
        chooser.setTitle("Export report");
        chooser.setInitialFileName(initialName);
        chooser.getExtensionFilters().add(filter);
        return chooser.showSaveDialog(owner);
    }

    @FunctionalInterface
    private interface ExportWork {
        void run() throws Exception;
    }

    private static void run(Label statusLabel, File target, ExportWork work) {
        statusLabel.setText("Exporting…");
        Task<Void> task = new Task<>() {
            @Override
            protected Void call() throws Exception {
                work.run();
                return null;
            }
        };
        task.setOnSucceeded(event -> statusLabel.setText("Exported to " + target.getName()));
        task.setOnFailed(event -> statusLabel.setText("Export failed: " + task.getException().getMessage()));
        new Thread(task, "report-export").start();
    }
}
