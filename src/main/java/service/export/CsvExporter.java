package service.export;

import java.io.File;
import java.io.FileWriter;
import java.io.IOException;
import java.io.Writer;
import java.nio.charset.StandardCharsets;
import java.util.List;

/**
 * RFC-4180 CSV writer for the report screens. Takes already-fetched rows and
 * never touches the database — the single-connection pool means an exporter
 * that acquired a connection while a caller still held one would deadlock.
 */
public final class CsvExporter {

    private CsvExporter() {
    }

    public static void write(File target, List<String> headers, List<List<String>> rows) throws IOException {
        try (Writer out = new FileWriter(target, StandardCharsets.UTF_8)) {
            out.write(toLine(headers));
            for (List<String> row : rows) {
                out.write(toLine(row));
            }
        }
    }

    private static String toLine(List<String> cells) {
        StringBuilder sb = new StringBuilder();
        for (int i = 0; i < cells.size(); i++) {
            if (i > 0) {
                sb.append(',');
            }
            sb.append(escape(cells.get(i)));
        }
        return sb.append("\r\n").toString();
    }

    private static String escape(String cell) {
        String value = cell == null ? "" : cell;
        if (value.contains(",") || value.contains("\"") || value.contains("\n") || value.contains("\r")) {
            return '"' + value.replace("\"", "\"\"") + '"';
        }
        return value;
    }
}
