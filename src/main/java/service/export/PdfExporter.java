package service.export;

import com.lowagie.text.Document;
import com.lowagie.text.Element;
import com.lowagie.text.Font;
import com.lowagie.text.FontFactory;
import com.lowagie.text.PageSize;
import com.lowagie.text.Paragraph;
import com.lowagie.text.Phrase;
import com.lowagie.text.pdf.PdfPCell;
import com.lowagie.text.pdf.PdfPTable;
import com.lowagie.text.pdf.PdfWriter;
import java.awt.Color;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;
import java.util.List;

/**
 * One shared PDF table renderer (openpdf) for every report screen — matches
 * the web app's dompdf report layout: title, meta lines, striped table,
 * generated-at footer. Takes already-fetched rows and never touches the
 * database (single-connection pool: a nested acquisition would deadlock).
 */
public final class PdfExporter {

    private PdfExporter() {
    }

    public static void write(File target, String title, List<String> metaLines,
                             List<String> headers, List<List<String>> rows) throws IOException {
        // document.close() must run while the stream is still open (it writes
        // the PDF trailer), so it happens inside the try body, not a finally.
        Document document = new Document(headers.size() > 6 ? PageSize.A4.rotate() : PageSize.A4, 36, 36, 36, 36);
        try (FileOutputStream out = new FileOutputStream(target)) {
            PdfWriter.getInstance(document, out);
            document.open();

            Font titleFont = FontFactory.getFont(FontFactory.HELVETICA_BOLD, 16);
            Font metaFont = FontFactory.getFont(FontFactory.HELVETICA, 9, Color.DARK_GRAY);
            Font headerFont = FontFactory.getFont(FontFactory.HELVETICA_BOLD, 9);
            Font cellFont = FontFactory.getFont(FontFactory.HELVETICA, 9);

            document.add(new Paragraph(title, titleFont));
            for (String line : metaLines) {
                document.add(new Paragraph(line, metaFont));
            }
            document.add(new Paragraph(" "));

            PdfPTable table = new PdfPTable(headers.size());
            table.setWidthPercentage(100);
            table.setHeaderRows(1);

            for (String header : headers) {
                PdfPCell cell = new PdfPCell(new Phrase(header, headerFont));
                cell.setBackgroundColor(new Color(0xF3, 0xF3, 0xF3));
                cell.setPadding(5f);
                table.addCell(cell);
            }

            boolean stripe = false;
            for (List<String> row : rows) {
                for (String value : row) {
                    PdfPCell cell = new PdfPCell(new Phrase(value == null ? "" : value, cellFont));
                    cell.setPadding(4f);
                    if (stripe) {
                        cell.setBackgroundColor(new Color(0xFA, 0xFA, 0xFA));
                    }
                    table.addCell(cell);
                }
                stripe = !stripe;
            }

            if (rows.isEmpty()) {
                PdfPCell empty = new PdfPCell(new Phrase("No data for this report.", cellFont));
                empty.setColspan(headers.size());
                empty.setPadding(8f);
                empty.setHorizontalAlignment(Element.ALIGN_CENTER);
                table.addCell(empty);
            }

            document.add(table);

            Paragraph footer = new Paragraph(
                    "Generated " + LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm")),
                    metaFont);
            footer.setSpacingBefore(10f);
            document.add(footer);

            document.close();
        }
    }
}
