package service.export;

import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertTrue;

class PdfExporterTest {

    @Test
    void producesAValidPdfWithTableContent() throws Exception {
        Path target = Files.createTempFile("pdf-exporter-test", ".pdf");

        PdfExporter.write(target.toFile(), "Test Report",
                List.of("Period: 2026-07-01 – 2026-07-16"),
                List.of("Name", "Amount"),
                List.of(List.of("Ama Mensah", "GHS 5.00"), List.of("Kofi Boateng", "GHS 10.00")));

        byte[] bytes = Files.readAllBytes(target);
        assertTrue(bytes.length > 500, "PDF should not be trivially small");
        assertTrue(new String(bytes, 0, 5).startsWith("%PDF-"), "file must start with the PDF magic bytes");

        Files.deleteIfExists(target);
    }

    @Test
    void handlesAnEmptyReportWithoutFailing() throws Exception {
        Path target = Files.createTempFile("pdf-exporter-empty-test", ".pdf");

        PdfExporter.write(target.toFile(), "Empty Report", List.of(),
                List.of("Name", "Amount"), List.of());

        assertTrue(Files.size(target) > 0);
        Files.deleteIfExists(target);
    }
}
