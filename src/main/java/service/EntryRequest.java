package service;

import enums.PaymentMethod;
import enums.TransactionType;
import java.time.Instant;
import java.util.List;

/**
 * Everything {@link LedgerService#post} needs to post one balanced journal
 * entry — the Java mirror of the backend's {@code App\Services\Ledger\EntryData}.
 * Fluent setters so call sites read like named arguments.
 */
public class EntryRequest {

    private TransactionType type;
    private List<LedgerLine> lines;
    private PaymentMethod paymentMethod = PaymentMethod.CASH;
    private String origin = "desktop";
    private String recordedBy;
    private Instant recordedAt;
    private String clientReference;
    private String description;
    private String meta;

    public static EntryRequest of(TransactionType type, List<LedgerLine> lines) {
        EntryRequest request = new EntryRequest();
        request.type = type;
        request.lines = lines;
        return request;
    }

    public EntryRequest paymentMethod(PaymentMethod paymentMethod) { this.paymentMethod = paymentMethod; return this; }
    public EntryRequest origin(String origin) { this.origin = origin; return this; }
    public EntryRequest recordedBy(String recordedBy) { this.recordedBy = recordedBy; return this; }
    public EntryRequest recordedAt(Instant recordedAt) { this.recordedAt = recordedAt; return this; }
    public EntryRequest clientReference(String clientReference) { this.clientReference = clientReference; return this; }
    public EntryRequest description(String description) { this.description = description; return this; }
    public EntryRequest meta(String meta) { this.meta = meta; return this; }

    public TransactionType getType() { return type; }
    public List<LedgerLine> getLines() { return lines; }
    public PaymentMethod getPaymentMethod() { return paymentMethod; }
    public String getOrigin() { return origin; }
    public String getRecordedBy() { return recordedBy; }
    public Instant getRecordedAt() { return recordedAt; }
    public String getClientReference() { return clientReference; }
    public String getDescription() { return description; }
    public String getMeta() { return meta; }
}
