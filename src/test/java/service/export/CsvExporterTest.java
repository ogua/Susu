package service.export;

import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

class CsvExporterTest {

    @Test
    void writesHeadersAndRowsWithRfc4180Quoting() throws Exception {
        Path target = Files.createTempFile("csv-exporter-test", ".csv");

        CsvExporter.write(target.toFile(),
                List.of("Name", "Note", "Amount"),
                List.of(
                        List.of("Ama Mensah", "plain", "GHS 5.00"),
                        List.of("Kofi, Jr.", "says \"hi\"", "GHS 10.00")));

        List<String> lines = Files.readAllLines(target);
        assertEquals(3, lines.size());
        assertEquals("Name,Note,Amount", lines.get(0));
        assertEquals("Ama Mensah,plain,GHS 5.00", lines.get(1));
        // Comma and quotes force quoting; embedded quotes double up.
        assertEquals("\"Kofi, Jr.\",\"says \"\"hi\"\"\",GHS 10.00", lines.get(2));

        Files.deleteIfExists(target);
    }

    @Test
    void treatsNullCellsAsEmpty() throws Exception {
        Path target = Files.createTempFile("csv-exporter-null-test", ".csv");

        CsvExporter.write(target.toFile(),
                List.of("A", "B"),
                List.of(java.util.Arrays.asList(null, "x")));

        List<String> lines = Files.readAllLines(target);
        assertEquals(",x", lines.get(1));
        assertTrue(lines.get(0).startsWith("A,"));

        Files.deleteIfExists(target);
    }
}
